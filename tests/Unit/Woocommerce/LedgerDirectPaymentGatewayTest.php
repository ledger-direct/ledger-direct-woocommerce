<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Woocommerce;

use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;
use PHPUnit\Framework\TestCase;

class LedgerDirectPaymentGatewayTest extends TestCase
{
    public function testCheckoutPaymentTypesMapToCoreBaseAssets(): void
    {
        $this->assertSame('XRP', OrderTransactionService::BASE_ASSET_BY_PAYMENT_TYPE[LedgerDirectPaymentGateway::XRP_PAYMENT_ID]);
        $this->assertSame('RLUSD', OrderTransactionService::BASE_ASSET_BY_PAYMENT_TYPE[LedgerDirectPaymentGateway::RLUSD_PAYMENT_ID]);
        $this->assertSame('USDC', OrderTransactionService::BASE_ASSET_BY_PAYMENT_TYPE[LedgerDirectPaymentGateway::USDC_PAYMENT_ID]);
    }

    public function testGatewayRegistersUnderItsStableId(): void
    {
        $this->assertSame('ledger-direct', LedgerDirectPaymentGateway::ID);
    }
}
