<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Cron\SettlePendingOrders;
use Hardcastle\LedgerDirect\Port\WpConfigProvider;
use Hardcastle\LedgerDirect\Service\ServiceFactory;
use Hardcastle\LedgerDirect\Woocommerce\PaymentIncompleteStatus;
use LedgerDirect;
use WC_Order;

class SettlePendingOrdersTest extends TestCase
{
    private XrplNetworkStub $network;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ledger_direct_xrpl_tx");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ledger_direct_xrpl_destination_tag");

        update_option(WpConfigProvider::OPTION_NAME, [
            'enabled' => 'yes',
            WpConfigProvider::KEY_NETWORK => 'testnet',
            WpConfigProvider::KEY_TESTNET_ACCOUNT => XrplNetworkStub::ACCOUNT,
            WpConfigProvider::KEY_QUOTE_EXPIRY => '15',
        ]);

        $this->network = new XrplNetworkStub();
        $this->network->install();
        ServiceFactory::setInstance(null);
    }

    protected function tearDown(): void
    {
        $this->network->remove();
        ServiceFactory::setInstance(null);

        parent::tearDown();
    }

    private function pendingOrder(): WC_Order
    {
        $order = wc_create_order();
        $order->set_currency('EUR');
        $order->set_total('100');
        $order->set_payment_method('ledger-direct');
        $order->set_status('pending');
        $order->save();

        return $order;
    }

    public function testSettlesAPaidPendingOrderWithoutAPageVisit(): void
    {
        $service = ServiceFactory::getInstance()->getOrderTransactionService();

        $paidOrder = $this->pendingOrder();
        $paidIntent = $service->prepareOrderForXrpl($paidOrder, 'xrp');

        $unpaidOrder = $this->pendingOrder();
        $service->prepareOrderForXrpl($unpaidOrder, 'xrp');

        $this->network->addXrpPayment($paidIntent->destinationTag, '200000000', 'HASH-CRON');
        $requestsBefore = count($this->network->requests);

        SettlePendingOrders::run();

        $paidOrder = wc_get_order($paidOrder->get_id());
        $this->assertTrue($paidOrder->is_paid());
        $this->assertSame('HASH-CRON', $paidOrder->get_transaction_id());

        $unpaidOrder = wc_get_order($unpaidOrder->get_id());
        $this->assertFalse($unpaidOrder->is_paid());
        $this->assertSame('pending', $unpaidOrder->get_status());

        // One account_tx sync for both orders on the same account.
        $rpcCalls = array_filter(
            array_slice($this->network->requests, $requestsBefore),
            static fn (string $url): bool => str_contains($url, 'rippletest.net')
        );
        $this->assertCount(1, $rpcCalls);
    }

    /**
     * The merchant cancelled, the customer paid anyway, and no other order is
     * waiting: nothing would ever have looked at the account. The job syncs the
     * configured account on every run, so the payment is on record - in the
     * transaction table and on the order's panel - while the order stays as the
     * merchant left it.
     */
    public function testAPaymentOnACancelledOrderReachesTheTableWithoutAnyOpenOrder(): void
    {
        $service = ServiceFactory::getInstance()->getOrderTransactionService();

        $order = $this->pendingOrder();
        $intent = $service->prepareOrderForXrpl($order, 'xrp');
        $order->update_status('cancelled', 'closed by the merchant');

        $this->network->addXrpPayment($intent->destinationTag, '200000000', 'HASH-LATE');
        $requestsBefore = count($this->network->requests);

        SettlePendingOrders::run();

        global $wpdb;
        $this->assertSame(
            'HASH-LATE',
            $wpdb->get_var($wpdb->prepare("SELECT hash FROM {$wpdb->prefix}ledger_direct_xrpl_tx WHERE destination_tag = %d", $intent->destinationTag))
        );
        $order = wc_get_order($order->get_id());
        $this->assertSame('cancelled', $order->get_status());
        $this->assertFalse($order->is_paid());
        $rpcCalls = array_filter(
            array_slice($this->network->requests, $requestsBefore),
            static fn (string $url): bool => str_contains($url, 'rippletest.net')
        );
        $this->assertCount(1, $rpcCalls, 'one sync of the configured account');
    }

    public function testIgnoresOrdersOfOtherGateways(): void
    {
        $order = $this->pendingOrder();
        $order->set_payment_method('cod');
        $order->save();

        SettlePendingOrders::run();

        $this->assertSame('pending', wc_get_order($order->get_id())->get_status());
    }

    /**
     * The safety net must not give up on an order after a short first
     * payment: the top-up that arrives later settles it on a later run.
     */
    public function testATopUpAfterAPartialPaymentSettlesOnALaterRun(): void
    {
        $service = ServiceFactory::getInstance()->getOrderTransactionService();

        $order = $this->pendingOrder();
        $intent = $service->prepareOrderForXrpl($order, 'xrp');

        $this->network->addXrpPayment($intent->destinationTag, '150000000', 'HASH-FIRST', 90000000);
        SettlePendingOrders::run();

        $order = wc_get_order($order->get_id());
        $this->assertFalse($order->is_paid());
        $this->assertSame(PaymentIncompleteStatus::STATUS, $order->get_status());
        $this->assertSame('HASH-FIRST', $order->get_meta(LedgerDirect::META_KEY)['hash']);

        $this->network->addXrpPayment($intent->destinationTag, '50000000', 'HASH-TOPUP', 90000010);
        SettlePendingOrders::run();

        $order = wc_get_order($order->get_id());
        $this->assertTrue($order->is_paid());
        $this->assertSame('HASH-TOPUP', $order->get_transaction_id());
    }
}
