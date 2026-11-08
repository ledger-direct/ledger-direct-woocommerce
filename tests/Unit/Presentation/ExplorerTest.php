<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Presentation;

use Hardcastle\LedgerDirect\Presentation\Explorer;
use PHPUnit\Framework\TestCase;

class ExplorerTest extends TestCase
{
    public function testEachChainAndNetworkHasItsExplorer(): void
    {
        self::assertSame('https://livenet.xrpl.org/transactions/H', Explorer::transactionUrl('XRPL', 'mainnet', 'H'));
        self::assertSame('https://testnet.xrpl.org/transactions/H', Explorer::transactionUrl('XRPL', 'testnet', 'H'));
        self::assertSame('https://explorer.xahau.network/tx/H', Explorer::transactionUrl('XAHAU', 'mainnet', 'H'));
        self::assertSame('https://explorer.xahau-test.net/tx/H', Explorer::transactionUrl('XAHAU', 'testnet', 'H'));
    }

    public function testNoLinkWithoutAHashOrAnExplorer(): void
    {
        self::assertNull(Explorer::transactionUrl('XRPL', 'testnet', null));
        self::assertNull(Explorer::transactionUrl('XRPL', 'testnet', ''));
        self::assertNull(Explorer::transactionUrl('STELLAR', 'testnet', 'H'));
        self::assertSame('', Explorer::transactionBase('XRPL', 'devnet'));
    }
}
