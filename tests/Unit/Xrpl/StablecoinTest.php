<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Xrpl;

use Exception;
use Hardcastle\LedgerDirect\Xrpl\Stablecoin\RLUSD;
use Hardcastle\LedgerDirect\Xrpl\Stablecoin\USDC;
use PHPUnit\Framework\TestCase;

class StablecoinTest extends TestCase
{
    public function testRlusdGetAmountReturnsIssuerCurrencyAndValueForBothNetworks(): void
    {
        foreach (['mainnet', 'testnet'] as $network) {
            $amount = RLUSD::getAmount($network, '12.34');

            $this->assertSame('12.34', $amount['value']);
            $this->assertNotEmpty($amount['issuer']);
            $this->assertSame('524C555344000000000000000000000000000000', $amount['currency']);
        }
    }

    public function testUsdcGetAmountReturnsIssuerCurrencyAndValueForBothNetworks(): void
    {
        foreach (['mainnet', 'testnet'] as $network) {
            $amount = USDC::getAmount($network, '12.34');

            $this->assertSame('12.34', $amount['value']);
            $this->assertNotEmpty($amount['issuer']);
            $this->assertSame('5553444300000000000000000000000000000000', $amount['currency']);
        }
    }

    public function testUnsupportedNetworkThrows(): void
    {
        $this->expectException(Exception::class);

        RLUSD::getAmount('devnet', '1');
    }
}
