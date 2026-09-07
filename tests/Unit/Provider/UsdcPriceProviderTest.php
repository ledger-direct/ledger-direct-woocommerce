<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider;

use Hardcastle\LedgerDirect\Provider\UsdcPriceProvider;
use PHPUnit\Framework\TestCase;

class UsdcPriceProviderTest extends TestCase
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
        $provider = new UsdcPriceProvider();

        $this->assertSame(1.0, $provider->getCurrentExchangeRate('USD'));
    }

    public function testNonUsdCurrencyIsResolvedViaOracle(): void
    {
        $this->stubHttpResponse('{"usd-coin":{"eur":0.91}}');
        $provider = new UsdcPriceProvider();

        $this->assertSame(0.91, $provider->getCurrentExchangeRate('EUR'));
    }

    public function testCheckPricePlausibility(): void
    {
        $provider = new UsdcPriceProvider();

        $this->assertTrue($provider->checkPricePlausibility(1.0));
        $this->assertFalse($provider->checkPricePlausibility(0.0));
    }
}
