<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Xrpl;

use Exception;
use Hardcastle\LedgerDirect\Xrpl\Networks;
use PHPUnit\Framework\TestCase;

class NetworksTest extends TestCase
{
    public function testMainnetAndTestnetAreDefined(): void
    {
        $mainnet = Networks::get('mainnet');
        $this->assertSame(0, $mainnet['networkId']);
        $this->assertNotEmpty($mainnet['jsonRpcUrl']);

        $testnet = Networks::get('testnet');
        $this->assertSame(1, $testnet['networkId']);
        $this->assertNotEmpty($testnet['jsonRpcUrl']);
    }

    public function testUnknownNetworkThrows(): void
    {
        $this->expectException(Exception::class);

        Networks::get('devnet');
    }
}
