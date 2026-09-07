<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Cron\SettlePendingOrders;
use Hardcastle\LedgerDirect\Port\WpConfigProvider;
use Hardcastle\LedgerDirect\Service\ServiceFactory;
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

    public function testIgnoresOrdersOfOtherGateways(): void
    {
        $order = $this->pendingOrder();
        $order->set_payment_method('cod');
        $order->save();

        SettlePendingOrders::run();

        $this->assertSame('pending', wc_get_order($order->get_id())->get_status());
    }
}
