<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider\Oracle;

use Hardcastle\LedgerDirect\Provider\Oracle\KrakenOracle;
use PHPUnit\Framework\TestCase;

class KrakenOracleTest extends TestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    private function stubHttpResponse(string $body): void
    {
        add_filter('pre_http_request', function () use ($body) {
            return [
                'body' => $body,
                'response' => ['code' => 200, 'message' => 'OK'],
                'headers' => [],
            ];
        }, 10, 3);
    }

    /**
     * Regression test for W4: KrakenOracle used to read a hardcoded
     * 'XXRPZUSD' key, so every pair other than XRP/USD silently returned 0.0.
     */
    public function testReadsPriceRegardlessOfKrakenPairKey(): void
    {
        $this->stubHttpResponse('{"error":[],"result":{"XXRPZEUR":{"c":["0.75","10.0"]}}}');
        $oracle = new KrakenOracle();

        $this->assertSame(0.75, $oracle->getCurrentPriceForPair('XRP', 'EUR'));
    }

    public function testStillReadsTheOriginalXrpUsdPairKey(): void
    {
        $this->stubHttpResponse('{"error":[],"result":{"XXRPZUSD":{"c":["0.5","10.0"]}}}');
        $oracle = new KrakenOracle();

        $this->assertSame(0.5, $oracle->getCurrentPriceForPair('XRP', 'USD'));
    }

    public function testReturnsZeroWhenResultIsEmpty(): void
    {
        $this->stubHttpResponse('{"error":["EQuery:Unknown asset pair"],"result":{}}');
        $oracle = new KrakenOracle();

        $this->assertSame(0.0, $oracle->getCurrentPriceForPair('XRP', 'ZZZ'));
    }
}
