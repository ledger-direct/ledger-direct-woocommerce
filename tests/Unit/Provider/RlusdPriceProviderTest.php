<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider;

use Hardcastle\LedgerDirect\Provider\RlusdPriceProvider;
use PHPUnit\Framework\TestCase;

class RlusdPriceProviderTest extends TestCase
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

    public function testUsdIsPeggedWithoutCallingAnyOracle(): void
    {
        // RLUSD is pegged 1:1 to USD; no pre_http_request stub is registered, so
        // a real HTTP call here would fail the test (blocked in the sandbox) or
        // hang, proving no oracle is actually queried.
        $provider = new RlusdPriceProvider();

        $this->assertSame(1.0, $provider->getCurrentExchangeRate('USD'));
    }

    public function testNonUsdCurrencyIsResolvedViaOracle(): void
    {
        $this->stubHttpResponse('{"ripple-usd":{"eur":0.93}}');
        $provider = new RlusdPriceProvider();

        $this->assertSame(0.93, $provider->getCurrentExchangeRate('EUR'));
    }

    public function testCheckPricePlausibility(): void
    {
        $provider = new RlusdPriceProvider();

        $this->assertTrue($provider->checkPricePlausibility(1.0));
        $this->assertFalse($provider->checkPricePlausibility(0.0));
    }
}
