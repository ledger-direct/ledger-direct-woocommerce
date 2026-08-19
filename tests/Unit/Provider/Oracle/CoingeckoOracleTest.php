<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider\Oracle;

use Hardcastle\LedgerDirect\Provider\Oracle\CoingeckoOracle;
use PHPUnit\Framework\TestCase;

class CoingeckoOracleTest extends TestCase
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

    public function testMapsKnownCurrencyCodesToCoingeckoIds(): void
    {
        $this->stubHttpResponse('{"ripple":{"usd":0.6}}');
        $oracle = new CoingeckoOracle();

        $this->assertSame(0.6, $oracle->getCurrentPriceForPair('XRP', 'USD'));
    }

    public function testMapsRlusdAndUsdc(): void
    {
        $this->stubHttpResponse('{"ripple-usd":{"eur":0.92}}');
        $oracle = new CoingeckoOracle();
        $this->assertSame(0.92, $oracle->getCurrentPriceForPair('RLUSD', 'EUR'));

        $this->stubHttpResponse('{"usd-coin":{"eur":0.91}}');
        $oracle = new CoingeckoOracle();
        $this->assertSame(0.91, $oracle->getCurrentPriceForPair('USDC', 'EUR'));
    }

    public function testUnmappedCodeFallsBackToLowercasedCode(): void
    {
        // Not in the mapping table (e.g. EURC, see W7) - falls back to
        // strtolower($code), which will not match a real Coingecko id.
        $this->stubHttpResponse('{}');
        $oracle = new CoingeckoOracle();

        $this->assertSame(0.0, $oracle->getCurrentPriceForPair('XRP', 'EURC'));
    }
}
