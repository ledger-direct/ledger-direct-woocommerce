<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Core\Payment\AssetNotAcceptedException;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Woocommerce\PaymentIncompleteStatus;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Port\WpConfigProvider;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\ServiceFactory;
use LedgerDirect;
use WC_Order;

/**
 * The core-backed payment flow against a real WooCommerce order and the
 * real database, with the network stubbed.
 */
class OrderTransactionServiceTest extends TestCase
{
    private XrplNetworkStub $network;

    private OrderTransactionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ledger_direct_xrpl_tx");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ledger_direct_xrpl_destination_tag");
        delete_transient('ledger_direct_ledger-direct.rate.v1.testnet.XRP.EUR');
        delete_transient('ledger_direct_ledger-direct.rate.v1.testnet.RLUSD.EUR');

        update_option(WpConfigProvider::OPTION_NAME, [
            'enabled' => 'yes',
            WpConfigProvider::KEY_NETWORK => 'testnet',
            WpConfigProvider::KEY_TESTNET_ACCOUNT => XrplNetworkStub::ACCOUNT,
            WpConfigProvider::KEY_RLUSD_ENABLED => 'yes',
            WpConfigProvider::KEY_USDC_ENABLED => 'no',
            WpConfigProvider::KEY_QUOTE_EXPIRY => '15',
        ]);

        $this->network = new XrplNetworkStub(xrpRate: 0.5, stablecoinRate: 0.9);
        $this->network->install();

        ServiceFactory::setInstance(null);
        $this->service = ServiceFactory::getInstance()->getOrderTransactionService();
    }

    protected function tearDown(): void
    {
        $this->network->remove();
        ServiceFactory::setInstance(null);

        parent::tearDown();
    }

    private function order(string $currency = 'EUR', string $total = '100'): WC_Order
    {
        $order = wc_create_order();
        $order->set_currency($currency);
        $order->set_total($total);
        $order->set_payment_method('ledger-direct');
        $order->save();

        return $order;
    }

    public function testQuotesAnXrpOrderAndStoresASchemaV1Record(): void
    {
        $order = $this->order();
        $before = time();

        $intent = $this->service->prepareOrderForXrpl($order, 'xrp');

        $this->assertSame('xrp-payment', $intent->type);
        $this->assertSame('XRP', $intent->baseAsset);
        $this->assertSame('EUR', $intent->quoteCurrency);
        $this->assertSame('XRP/EUR', $intent->pairing);
        $this->assertSame(0.5, $intent->exchangeRate);
        $this->assertSame(200.0, $intent->amountRequested);
        $this->assertSame('testnet', $intent->network);
        $this->assertSame(XrplNetworkStub::ACCOUNT, $intent->destinationAccount);
        $this->assertGreaterThanOrEqual(10000, $intent->destinationTag);
        $this->assertGreaterThanOrEqual($before + 15 * 60, $intent->expiry);

        $stored = wc_get_order($order->get_id())->get_meta(LedgerDirect::META_KEY);
        $this->assertSame(1, $stored['schema_version']);
        $this->assertSame($intent->destinationTag, $stored['destination_tag']);
        $this->assertArrayNotHasKey('version', $stored);
    }

    public function testQuotesAStablecoinOrderWithTheRegistryIssuer(): void
    {
        $intent = $this->service->prepareOrderForXrpl($this->order(), 'rlusd');

        $this->assertSame('rlusd-payment', $intent->type);
        $this->assertSame('RLUSD', $intent->baseAsset);
        $this->assertIsArray($intent->amountRequested);
        $this->assertSame('rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV', $intent->amountRequested['issuer']);
        $this->assertSame('111.11', $intent->amountRequested['value']);
        $this->assertSame('111.11', $intent->amountRequestedValue());
    }

    public function testADisabledAssetIsRefused(): void
    {
        $this->expectException(AssetNotAcceptedException::class);

        $this->service->prepareOrderForXrpl($this->order(), 'usdc');
    }

    public function testTwoOrdersGetDifferentTagsAndARequoteKeepsItsTag(): void
    {
        $first = $this->service->prepareOrderForXrpl($this->order(), 'xrp');
        $secondOrder = $this->order();
        $second = $this->service->prepareOrderForXrpl($secondOrder, 'xrp');

        $this->assertNotSame($first->destinationTag, $second->destinationTag);

        $requoted = $this->service->prepareOrderForXrpl($secondOrder, 'xrp');
        $this->assertSame($second->destinationTag, $requoted->destinationTag);
    }

    public function testReadsALegacyRecordWrittenBeforeTheRetrofit(): void
    {
        $order = $this->order();
        $order->update_meta_data(LedgerDirect::META_KEY, [
            'chain' => 'XRPL',
            'network' => 'testnet',
            'version' => 1.0,
            'destination_account' => XrplNetworkStub::ACCOUNT,
            'destination_tag' => 777777,
            'expiry' => time() + 600,
            'pairing' => 'XRP/EUR',
            'exchange_rate' => 0.5,
            'amount_requested' => 200.0,
            'type' => 'xrp',
        ]);
        $order->save();

        $intent = $this->service->readPaymentIntent($order);

        $this->assertInstanceOf(PaymentIntent::class, $intent);
        $this->assertSame(777777, $intent->destinationTag);
        $this->assertSame('XRP', $intent->baseAsset);

        // A requote of the legacy order keeps the tag the customer may have used.
        $requoted = $this->service->prepareOrderForXrpl($order, 'xrp');
        $this->assertSame(777777, $requoted->destinationTag);
        $this->assertSame(1, wc_get_order($order->get_id())->get_meta(LedgerDirect::META_KEY)['schema_version']);
    }

    public function testAnOrderWithoutARecordReadsAsNull(): void
    {
        $this->assertNull($this->service->readPaymentIntent($this->order()));
    }

    public function testSyncMatchesAnIncomingPaymentAndTheCoreSettlesIt(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'xrp');

        $this->assertNull($this->service->syncOrderTransactionWithXrpl($order));

        $this->network->addXrpPayment($intent->destinationTag, '200000000', 'HASH-PAID');

        $fulfilled = $this->service->syncOrderTransactionWithXrpl($order);

        $this->assertInstanceOf(PaymentIntent::class, $fulfilled);
        $this->assertSame('HASH-PAID', $fulfilled->hash);
        $this->assertSame(200.0, $fulfilled->amountPaid);
        $this->assertTrue($this->service->isSettled($fulfilled));
        $this->assertNull($this->service->shortfall($fulfilled));

        $stored = wc_get_order($order->get_id())->get_meta(LedgerDirect::META_KEY);
        $this->assertSame('HASH-PAID', $stored['hash']);
        $this->assertSame(200.0, $stored['amount_paid']);
    }

    public function testAnUnderpaymentIsRecordedButDoesNotSettle(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'xrp');

        $this->network->addXrpPayment($intent->destinationTag, '150000000', 'HASH-SHORT');

        $fulfilled = $this->service->syncOrderTransactionWithXrpl($order);

        $this->assertInstanceOf(PaymentIntent::class, $fulfilled);
        $this->assertFalse($this->service->isSettled($fulfilled));
        $this->assertSame('50', $this->service->shortfall($fulfilled));
    }

    public function testASlightUnderpaymentWithinToleranceSettles(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'xrp');

        // 0.1 % short: within the core's 0.15 % native-asset tolerance.
        $this->network->addXrpPayment($intent->destinationTag, '199800000', 'HASH-NEAR');

        $fulfilled = $this->service->syncOrderTransactionWithXrpl($order);

        $this->assertTrue($this->service->isSettled($fulfilled));
    }

    public function testAPaymentFromTheWrongIssuerNeverSettlesAStablecoinOrder(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'rlusd');

        $this->network->addIssuedCurrencyPayment($intent->destinationTag, [
            'currency' => $intent->amountRequested['currency'],
            'value' => '999',
            'issuer' => 'rSomeOtherIssuerXXXXXXXXXXXXXXXXXX',
        ], 'HASH-WRONG-ISSUER');

        $fulfilled = $this->service->syncOrderTransactionWithXrpl($order);

        $this->assertInstanceOf(PaymentIntent::class, $fulfilled);
        $this->assertFalse($this->service->isSettled($fulfilled));
        $this->assertSame('111.11', $this->service->shortfall($fulfilled));
    }

    /**
     * A token on an XRP order is a wrong-asset payment since core 0.8.1,
     * not noise: the customer paid and has to be told nothing was credited.
     * It must not crash the sync, and the XRP payment that follows must be
     * the one that counts.
     */
    public function testATokenOnAnXrpOrderIsTheWrongAssetUntilTheRealPaymentArrives(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'xrp');

        $this->network->addIssuedCurrencyPayment($intent->destinationTag, [
            'currency' => '524C555344000000000000000000000000000000',
            'value' => '5',
            'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV',
        ], 'HASH-STRAY', 90000000);

        $wrong = $this->service->syncOrderTransactionWithXrpl($order);

        $this->assertInstanceOf(PaymentIntent::class, $wrong);
        $this->assertSame('HASH-STRAY', $wrong->hash);
        $this->assertFalse($this->service->isSettled($wrong));
        $this->assertSame(PaymentStatus::WRONG_ASSET, $this->service->paymentStatus($wrong)->state());
        $this->assertSame('200', $this->service->shortfall($wrong), 'nothing credited, the whole request still due');
        $this->assertSame(PaymentIncompleteStatus::STATUS, wc_get_order($order->get_id())->get_status());

        $this->network->addXrpPayment($intent->destinationTag, '200000000', 'HASH-REAL', 90000010);

        $fulfilled = $this->service->syncOrderTransactionWithXrpl($order);

        $this->assertInstanceOf(PaymentIntent::class, $fulfilled);
        $this->assertSame('HASH-REAL', $fulfilled->hash);
        $this->assertTrue($this->service->isSettled($fulfilled));
    }

    public function testAnExpiredQuoteIsRefreshedInPlace(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'xrp');

        $expired = $intent->toArray();
        $expired['expiry'] = time() - 10;
        $order->update_meta_data(LedgerDirect::META_KEY, $expired);
        $order->save();

        $refreshed = $this->service->refreshExpiredQuote($order, $this->service->readPaymentIntent($order));

        $this->assertGreaterThan(time(), $refreshed->expiry);
        $this->assertSame($intent->destinationTag, $refreshed->destinationTag);

        // A valid quote is left alone.
        $this->assertSame($refreshed, $this->service->refreshExpiredQuote($order, $refreshed));
    }

    /**
     * The bug shipped in 1.1.0: a first, short payment was recorded and then
     * the guard "amountPaid !== null" returned that record for ever. Neither
     * the page nor the cron synced again, so the customer who sent the
     * shortfall never got their order. Payments in the quoted asset add up.
     */
    public function testTwoPartialPaymentsAddUpAndTheTopUpSettles(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'xrp');

        $this->network->addXrpPayment($intent->destinationTag, '150000000', 'HASH-FIRST', 90000000);

        $partial = $this->service->syncOrderTransactionWithXrpl($order);

        $this->assertInstanceOf(PaymentIntent::class, $partial);
        $this->assertFalse($this->service->isSettled($partial));
        $this->assertSame('50', $this->service->shortfall($partial));

        $this->network->addXrpPayment($intent->destinationTag, '50000000', 'HASH-TOPUP', 90000010);

        $settled = $this->service->syncOrderTransactionWithXrpl($order);

        $this->assertInstanceOf(PaymentIntent::class, $settled);
        $this->assertSame('HASH-TOPUP', $settled->hash);
        $this->assertSame(200.0, $settled->amountPaid);
        $this->assertTrue($this->service->isSettled($settled));

        $stored = wc_get_order($order->get_id())->get_meta(LedgerDirect::META_KEY);
        $this->assertSame('HASH-TOPUP', $stored['hash']);
        $this->assertSame(200.0, $stored['amount_paid']);
    }

    public function testAPaymentInTheRightAssetReplacesOneFromTheWrongIssuer(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'rlusd');

        $this->network->addIssuedCurrencyPayment($intent->destinationTag, [
            'currency' => $intent->amountRequested['currency'],
            'value' => '999',
            'issuer' => 'rSomeOtherIssuerXXXXXXXXXXXXXXXXXX',
        ], 'HASH-WRONG-ISSUER', 90000000);

        $wrong = $this->service->syncOrderTransactionWithXrpl($order);

        $this->assertInstanceOf(PaymentIntent::class, $wrong);
        $this->assertSame('HASH-WRONG-ISSUER', $wrong->hash);
        $this->assertFalse($this->service->isSettled($wrong));

        $this->network->addIssuedCurrencyPayment($intent->destinationTag, [
            'currency' => $intent->amountRequested['currency'],
            'value' => $intent->amountRequested['value'],
            'issuer' => $intent->amountRequested['issuer'],
        ], 'HASH-RIGHT-ISSUER', 90000010);

        $settled = $this->service->syncOrderTransactionWithXrpl($order);

        $this->assertInstanceOf(PaymentIntent::class, $settled);
        $this->assertSame('HASH-RIGHT-ISSUER', $settled->hash);
        $this->assertSame($intent->amountRequested['value'], $settled->amountPaid['value']);
        $this->assertTrue($this->service->isSettled($settled));
    }

    public function testASettledOrderIsNotSyncedAgain(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'xrp');
        $this->network->addXrpPayment($intent->destinationTag, '200000000', 'HASH-PAID');
        $this->service->syncOrderTransactionWithXrpl($order);

        $requestsBefore = count($this->network->requests);

        $again = $this->service->syncOrderTransactionWithXrpl(wc_get_order($order->get_id()));

        $this->assertInstanceOf(PaymentIntent::class, $again);
        $this->assertSame('HASH-PAID', $again->hash);
        $this->assertCount($requestsBefore, $this->network->requests);
    }

    public function testAnUnchangedPartialPaymentIsNotSavedTwice(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'xrp');
        $this->network->addXrpPayment($intent->destinationTag, '150000000', 'HASH-SHORT');
        $this->service->syncOrderTransactionWithXrpl($order);

        $order = wc_get_order($order->get_id());
        $modifiedBefore = $order->get_date_modified()?->getTimestamp();
        $saves = 0;
        $counter = static function (int $orderId) use ($order, &$saves): void {
            if ($orderId === $order->get_id()) {
                ++$saves;
            }
        };
        add_action('woocommerce_update_order', $counter);

        $again = $this->service->syncOrderTransactionWithXrpl($order);

        remove_action('woocommerce_update_order', $counter);

        $this->assertInstanceOf(PaymentIntent::class, $again);
        $this->assertFalse($this->service->isSettled($again));
        $this->assertSame(0, $saves);
        $this->assertSame($modifiedBefore, wc_get_order($order->get_id())->get_date_modified()?->getTimestamp());
    }
}
