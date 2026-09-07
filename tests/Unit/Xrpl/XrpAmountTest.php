<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Xrpl;

use Exception;
use Hardcastle\LedgerDirect\Xrpl\XrpAmount;
use PHPUnit\Framework\TestCase;

class XrpAmountTest extends TestCase
{
    /**
     * @dataProvider dropsProvider
     */
    public function testDropsToXrp(string $drops, string $expected): void
    {
        $this->assertSame($expected, XrpAmount::dropsToXrp($drops));
    }

    public static function dropsProvider(): array
    {
        return [
            'exact whole XRP' => ['1000000', '1'],
            'exact 1000 XRP' => ['1000000000', '1000'],
            'fractional, trailing zeros stripped' => ['1500000', '1.5'],
            'fractional, all 6 places significant' => ['1000001', '1.000001'],
            'less than 1 XRP' => ['100000', '0.1'],
            'zero' => ['0', '0'],
            'negative amount' => ['-5000000', '-5'],
            'single drop' => ['1', '0.000001'],
        ];
    }

    public function testRejectsNonIntegerInput(): void
    {
        $this->expectException(Exception::class);

        XrpAmount::dropsToXrp('1.5');
    }

    public function testRejectsNonNumericInput(): void
    {
        $this->expectException(Exception::class);

        XrpAmount::dropsToXrp('not-a-number');
    }
}
