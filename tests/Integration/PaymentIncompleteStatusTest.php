<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Api\PaymentStatusEndpoint;
use Hardcastle\LedgerDirect\Cron\SettlePendingOrders;
use Hardcastle\LedgerDirect\Port\WpConfigProvider;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\ServiceFactory;
use Hardcastle\LedgerDirect\Woocommerce\PaymentIncompleteStatus;
use LedgerDirectAdmin;
use WC_Order;
use WP_REST_Request;

/**
 * A payment that arrives but does not pay the order moves it to "XRPL
 * payment incomplete": visible to the merchant, still open for the
 * customer, and explained on the order page.
 */
class PaymentIncompleteStatusTest extends TestCase
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
        delete_transient('ledger_direct_ledger-direct.sync.v1.testnet.' . XrplNetworkStub::ACCOUNT);

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
        delete_transient('ledger_direct_ledger-direct.sync.v1.testnet.' . XrplNetworkStub::ACCOUNT);

        parent::tearDown();
    }

    private function order(): WC_Order
    {
        $order = wc_create_order();
        $order->set_currency('EUR');
        $order->set_total('100');
        $order->set_payment_method('ledger-direct');
        $order->set_status('pending');
        $order->save();

        return $order;
    }

    private function poll(WC_Order $order): array
    {
        $request = new WP_REST_Request('GET', '/' . PaymentStatusEndpoint::REST_NAMESPACE . '/payment-status/' . $order->get_order_key());

        return rest_get_server()->dispatch($request)->get_data();
    }

    /**
     * @return string[] the notes on the order, newest first
     */
    private function notes(WC_Order $order): array
    {
        return array_map(
            static fn (object $note): string => (string) $note->content,
            wc_get_order_notes(['order_id' => $order->get_id(), 'type' => 'internal'])
        );
    }

    public function testTheStatusIsRegisteredAsAnOpenOne(): void
    {
        $statuses = wc_get_order_statuses();

        self::assertSame('XRPL payment incomplete', $statuses['wc-' . PaymentIncompleteStatus::STATUS]);
        // Listed right after "Pending payment", which it refines
        $keys = array_keys($statuses);
        self::assertSame(array_search('wc-pending', $keys, true) + 1, array_search('wc-' . PaymentIncompleteStatus::STATUS, $keys, true));

        $order = $this->order();
        $order->set_status(PaymentIncompleteStatus::STATUS);
        $order->save();

        // Persisted as such: the post status column is a varchar(20)
        self::assertSame(PaymentIncompleteStatus::STATUS, wc_get_order($order->get_id())->get_status());
        self::assertTrue(wc_get_order($order->get_id())->needs_payment());
        self::assertFalse(wc_get_order($order->get_id())->is_paid());
    }

    public function testAShortPaymentMovesTheOrderWithANoteAndATopUpSettlesIt(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'xrp');

        $this->network->addXrpPayment($intent->destinationTag, '150000000', 'HASH-HALF', 90000000);
        $payload = $this->poll($order);

        self::assertSame('partial', $payload['state']);
        $order = wc_get_order($order->get_id());
        self::assertSame(PaymentIncompleteStatus::STATUS, $order->get_status());
        self::assertTrue($order->needs_payment());
        $notes = $this->notes($order);
        self::assertStringContainsString('150 XRP received of 200 XRP requested, 50 XRP still due', $notes[0]);
        self::assertStringContainsString('HASH-HALF', $notes[0]);

        // A poll that sees nothing new adds no second note
        delete_transient('ledger_direct_ledger-direct.sync.v1.testnet.' . XrplNetworkStub::ACCOUNT);
        $this->poll($order);
        self::assertCount(count($notes), $this->notes(wc_get_order($order->get_id())));

        // The top-up settles from the incomplete status
        $this->network->addXrpPayment($intent->destinationTag, '50000000', 'HASH-TOPUP', 90000010);
        delete_transient('ledger_direct_ledger-direct.sync.v1.testnet.' . XrplNetworkStub::ACCOUNT);
        $payload = $this->poll($order);

        self::assertSame('settled', $payload['state']);
        self::assertArrayHasKey('redirect', $payload);
        $order = wc_get_order($order->get_id());
        self::assertTrue($order->is_paid());
        self::assertSame('HASH-TOPUP', $order->get_transaction_id());
    }

    public function testAWrongTokenIsNotedAsNotCredited(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'rlusd');
        $this->network->addIssuedCurrencyPayment($intent->destinationTag, [
            'currency' => $intent->amountRequested['currency'],
            'value' => '999',
            'issuer' => 'rSomeOtherIssuerXXXXXXXXXXXXXXXXXX',
        ], 'HASH-WRONG-ISSUER');

        $payload = $this->poll($order);

        self::assertSame('wrong_asset', $payload['state']);
        $order = wc_get_order($order->get_id());
        self::assertSame(PaymentIncompleteStatus::STATUS, $order->get_status());
        $note = $this->notes($order)[0];
        self::assertStringContainsString('another token and was not credited', $note);
        self::assertStringContainsString('The full ' . $intent->amountRequestedValue() . ' RLUSD is still due', $note);
        self::assertStringContainsString('HASH-WRONG-ISSUER', $note);
    }

    public function testTheBackgroundJobKeepsMatchingIncompleteOrders(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'xrp');

        $this->network->addXrpPayment($intent->destinationTag, '150000000', 'HASH-FIRST', 90000000);
        SettlePendingOrders::run();
        self::assertSame(PaymentIncompleteStatus::STATUS, wc_get_order($order->get_id())->get_status());

        $this->network->addXrpPayment($intent->destinationTag, '50000000', 'HASH-TOPUP', 90000010);
        SettlePendingOrders::run();

        $order = wc_get_order($order->get_id());
        self::assertTrue($order->is_paid());
        self::assertSame('HASH-TOPUP', $order->get_transaction_id());
    }

    public function testTheOrderPanelSaysWhatArrivedAndWhatIsDue(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'xrp');
        $this->network->addXrpPayment($intent->destinationTag, '150000000', 'HASH-HALF', 90000000);
        $this->poll($order);

        $panel = LedgerDirectAdmin::order_panel_values(wc_get_order($order->get_id()));

        self::assertSame('partial', $panel['state']);
        self::assertSame('Partially paid', $panel['state_label']);
        self::assertSame('200', $panel['amount_requested']);
        self::assertSame('150', $panel['amount_paid']);
        self::assertSame('50', $panel['shortfall']);
        self::assertSame((string) $intent->destinationTag, $panel['destination_tag']);
        self::assertSame('HASH-HALF', $panel['hash']);
        self::assertCount(1, $panel['transactions']);
        self::assertSame('150', $panel['transactions'][0]['delivered']);
        self::assertSame('https://testnet.xrpl.org/transactions/HASH-HALF', $panel['transactions'][0]['explorer_url']);

        ob_start();
        (new LedgerDirectAdmin())->render_order_panel(wc_get_order($order->get_id()));
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Partially paid', $html);
        self::assertStringContainsString('50 XRP', $html);
        self::assertStringContainsString('https://testnet.xrpl.org/transactions/HASH-HALF', $html);
    }

    public function testThePanelIsOnlyOfferedForOrdersOfThisGateway(): void
    {
        $order = $this->order();
        $order->set_payment_method('cod');
        $order->save();

        global $wp_meta_boxes;
        $wp_meta_boxes = [];
        (new LedgerDirectAdmin())->add_order_panel('shop_order', get_post($order->get_id()));
        self::assertEmpty($wp_meta_boxes['shop_order']['side']['default']['ledger-direct-order-panel'] ?? null);

        $ours = $this->order();
        (new LedgerDirectAdmin())->add_order_panel('shop_order', get_post($ours->get_id()));
        self::assertNotEmpty($wp_meta_boxes['shop_order']['side']['default']['ledger-direct-order-panel'] ?? null);
    }
}
