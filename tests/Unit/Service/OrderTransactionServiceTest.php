<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\OrderTransactionServiceMock;
use Mockery;
use Mockery\Mock;
use PHPUnit\Framework\TestCase;
use WC_Order;

class OrderTransactionServiceTest extends TestCase
{
    private OrderTransactionService $orderTransactionService;

    protected function setUp(): void
    {
        $this->orderTransactionService = OrderTransactionServiceMock::createInstance();
    }
    public function testGetCurrentXrpPriceForOrder(): void
    {
        $order = new WC_Order();
        $order->set_currency('EUR');
        $order->set_total(100);

        $result = $this->orderTransactionService->getCurrentXrpPriceForOrder($order);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('pairing', $result);
        $this->assertArrayHasKey('exchange_rate', $result);
        $this->assertArrayHasKey('amount_requested', $result);
    }
}