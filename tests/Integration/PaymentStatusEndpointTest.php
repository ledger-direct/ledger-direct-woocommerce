<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Api\PaymentStatusEndpoint;
use Hardcastle\LedgerDirect\Port\WpConfigProvider;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\ServiceFactory;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The payment-status endpoint against a real order and database, with the
 * network stubbed: the contract payload, the order-key rule, settlement in
 * the poll, and the throttle.
 */
class PaymentStatusEndpointTest extends TestCase
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

    private function order(string $paymentMethod = 'ledger-direct'): WC_Order
    {
        $order = wc_create_order();
        $order->set_currency('EUR');
        $order->set_total('100');
        $order->set_payment_method($paymentMethod);
        $order->set_status('pending');
        $order->save();

        return $order;
    }

    private function poll(string $orderKey): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', '/' . PaymentStatusEndpoint::REST_NAMESPACE . '/payment-status/' . $orderKey);

        return rest_get_server()->dispatch($request);
    }

    private function rpcCallsSince(int $requestsBefore): int
    {
        return count(array_filter(
            array_slice($this->network->requests, $requestsBefore),
            static fn (string $url): bool => str_contains($url, 'rippletest.net')
        ));
    }

    public function testAnswersTheContractPayloadWhileWaiting(): void
    {
        $order = $this->order();
        $this->service->prepareOrderForXrpl($order, 'xrp');

        $response = $this->poll($order->get_order_key());
        $payload = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('no-store', $response->get_headers()['Cache-Control']);
        $this->assertSame(
            ['schema_version', 'state', 'base_asset', 'amount_requested', 'amount_paid', 'shortfall', 'seconds_left'],
            array_keys($payload)
        );
        $this->assertSame(1, $payload['schema_version']);
        $this->assertSame('waiting', $payload['state']);
        $this->assertSame('XRP', $payload['base_asset']);
        $this->assertSame(200.0, $payload['amount_requested']);
        $this->assertNull($payload['amount_paid']);
        $this->assertNull($payload['shortfall']);
        $this->assertIsInt($payload['seconds_left']);
        $this->assertGreaterThan(0, $payload['seconds_left']);
        $this->assertArrayNotHasKey('redirect', $payload);
    }

    public function testAWrongKeyIsRefusedWithoutAHint(): void
    {
        $order = $this->order();
        $this->service->prepareOrderForXrpl($order, 'xrp');

        $wrongKey = $this->poll('wc_order_doesnotexist');
        $otherGateway = $this->order('cod');
        $otherGatewayKey = $this->poll($otherGateway->get_order_key());

        $this->assertSame(403, $wrongKey->get_status());
        $this->assertSame(['error' => 'forbidden'], $wrongKey->get_data());
        $this->assertSame(403, $otherGatewayKey->get_status());
        $this->assertSame(['error' => 'forbidden'], $otherGatewayKey->get_data());
    }

    public function testAnOrderWithoutAPaymentRecordIs404(): void
    {
        $order = $this->order();

        $response = $this->poll($order->get_order_key());

        $this->assertSame(404, $response->get_status());
        $this->assertSame(['error' => 'no_payment_intent'], $response->get_data());
    }

    public function testAPartialPaymentIsReportedAndKeepsTheOrderOpen(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'xrp');
        $this->network->addXrpPayment($intent->destinationTag, '150000000', 'HASH-HALF');

        $payload = $this->poll($order->get_order_key())->get_data();

        $this->assertSame('partial', $payload['state']);
        $this->assertSame(150.0, $payload['amount_paid']);
        $this->assertSame(50.0, $payload['shortfall']);
        $this->assertNull($payload['seconds_left']);
        $this->assertArrayNotHasKey('redirect', $payload);
        $this->assertFalse(wc_get_order($order->get_id())->is_paid());
    }

    public function testAWrongAssetPaymentIsNamedByTheCore(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'rlusd');
        $this->network->addIssuedCurrencyPayment($intent->destinationTag, [
            'currency' => $intent->amountRequested['currency'],
            'value' => '999',
            'issuer' => 'rSomeOtherIssuerXXXXXXXXXXXXXXXXXX',
        ], 'HASH-WRONG-ISSUER');

        $payload = $this->poll($order->get_order_key())->get_data();

        $this->assertSame('wrong_asset', $payload['state']);
        $this->assertSame('999', $payload['amount_paid']['value']);
        $this->assertSame('rSomeOtherIssuerXXXXXXXXXXXXXXXXXX', $payload['amount_paid']['issuer']);
        $this->assertSame($intent->amountRequested['issuer'], $payload['shortfall']['issuer']);
        $this->assertSame($intent->amountRequested['value'], $payload['shortfall']['value']);
    }

    public function testASettlingPaymentCompletesTheOrderInTheSamePoll(): void
    {
        $order = $this->order();
        $intent = $this->service->prepareOrderForXrpl($order, 'xrp');
        $this->network->addXrpPayment($intent->destinationTag, '200000000', 'HASH-PAID');

        $payload = $this->poll($order->get_order_key())->get_data();

        $this->assertSame('settled', $payload['state']);
        $this->assertArrayHasKey('redirect', $payload);
        $this->assertStringContainsString('order-received', $payload['redirect']);

        $order = wc_get_order($order->get_id());
        $this->assertTrue($order->is_paid());
        $this->assertSame('HASH-PAID', $order->get_transaction_id());
    }

    public function testTwoPollsInsideTheIntervalMakeOneNodeRequest(): void
    {
        $first = $this->order();
        $this->service->prepareOrderForXrpl($first, 'xrp');
        $second = $this->order();
        $this->service->prepareOrderForXrpl($second, 'xrp');
        $requestsBefore = count($this->network->requests);

        $a = $this->poll($first->get_order_key())->get_data();
        $b = $this->poll($second->get_order_key())->get_data();
        $c = $this->poll($first->get_order_key())->get_data();

        $this->assertSame(1, $this->rpcCallsSince($requestsBefore));
        $this->assertSame(array_keys($a), array_keys($b));
        $this->assertSame(array_keys($a), array_keys($c));
        $this->assertSame('waiting', $c['state']);
    }

    public function testAnOrderClosedByTheMerchantRedirectsAndIsNotSynced(): void
    {
        $order = $this->order();
        $this->service->prepareOrderForXrpl($order, 'xrp');
        $order->set_status('cancelled');
        $order->save();
        $requestsBefore = count($this->network->requests);

        $payload = $this->poll($order->get_order_key())->get_data();

        $this->assertSame('waiting', $payload['state']);
        $this->assertArrayHasKey('redirect', $payload);
        $this->assertSame(0, $this->rpcCallsSince($requestsBefore));
    }
}
