<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\OrderTransactionServiceMock;
use PHPUnit\Framework\TestCase;
use WC_Order;

class OrderTransactionServiceTest extends TestCase
{
    private OrderTransactionService $orderTransactionService;

    protected function setUp(): void
    {
        $this->orderTransactionService = OrderTransactionServiceMock::createInstance(exchangeRate: 2.0);
    }

    public function testGetCryptoPriceForOrderReturnsPricingMetadata(): void
    {
        $order = new WC_Order();
        $order->set_currency('EUR');
        $order->set_total(100);

        $result = $this->orderTransactionService->getCryptoPriceForOrder($order, 'XRP');

        $this->assertIsArray($result);
        $this->assertSame('XRP', $result['base_asset']);
        $this->assertSame('EUR', $result['quote_currency']);
        $this->assertSame('XRP/EUR', $result['pairing']);
        $this->assertSame(2.0, $result['exchange_rate']);
        $this->assertSame(50.0, $result['amount_requested']);
    }

    public function testGetCryptoPriceForOrderThrowsOnUnsupportedCode(): void
    {
        $order = new WC_Order();
        $order->set_currency('EUR');
        $order->set_total(100);

        $this->expectException(\Exception::class);

        $this->orderTransactionService->getCryptoPriceForOrder($order, 'DOGE');
    }
}