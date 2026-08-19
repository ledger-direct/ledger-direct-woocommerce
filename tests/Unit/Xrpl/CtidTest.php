<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Xrpl;

use Hardcastle\LedgerDirect\Xrpl\Ctid;
use PHPUnit\Framework\TestCase;

class CtidTest extends TestCase
{
    public function testToHexMatchesAnIndependentlyComputedValue(): void
    {
        // FILLER (0xC0000000) + ledgerIndex, then transactionIndex and networkId
        // each zero-padded to 4 hex digits and concatenated, uppercased.
        $this->assertSame('C577BDF1000D0000', Ctid::toHex(91733489, 13, 0));
    }

    public function testToHexIsAlways16UppercaseHexCharacters(): void
    {
        $hex = Ctid::toHex(1, 1, 1);

        $this->assertSame(16, strlen($hex));
        $this->assertSame($hex, strtoupper($hex));
        $this->assertMatchesRegularExpression('/^[0-9A-F]{16}$/', $hex);
    }

    public function testComponentsCanBeRecoveredFromTheHex(): void
    {
        $hex = Ctid::toHex(91733489, 13, 1);

        $ledgerIndex = hexdec(substr($hex, 0, 8)) - 0xc0000000;
        $transactionIndex = hexdec(substr($hex, 8, 4));
        $networkId = hexdec(substr($hex, 12, 4));

        $this->assertSame(91733489, $ledgerIndex);
        $this->assertSame(13, $transactionIndex);
        $this->assertSame(1, $networkId);
    }
}
