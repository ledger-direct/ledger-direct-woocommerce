<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider;

use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

class XrpPriceProviderTest extends TestCase
{
    private XrpPriceProvider $xrpPriceProvider;

    protected function setUp(): void
    {
        $this->stubHttpResponsesByHost([
            'binance.com' => '{"price": 0.5}',
            'coingecko.com' => '{"ripple":{"usd":0.5}}',
            'kraken.com' => '{"result":{"XXRPZUSD":{"c":["0.5"]}}}',
        ]);

        $this->xrpPriceProvider = new XrpPriceProvider();
    }

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    /**
     * @param array<string, string|WP_Error> $responsesByHost Maps a substring of the
     *        request URL's host to either a fake response body or a WP_Error to
     *        simulate that host's request failing.
     */
    private function stubHttpResponsesByHost(array $responsesByHost): void
    {
        add_filter('pre_http_request', function ($preempt, $parsedArgs, $url) use ($responsesByHost) {
            foreach ($responsesByHost as $host => $response) {
                if (str_contains($url, $host)) {
                    if ($response instanceof WP_Error) {
                        return $response;
                    }

                    return [
                        'body' => $response,
                        'response' => ['code' => 200, 'message' => 'OK'],
                        'headers' => [],
                    ];
                }
            }

            return $preempt;
        }, 10, 3);
    }

    public function testGetCurrentExchangeRate(): void
    {
        $this->assertEquals(0.5, $this->xrpPriceProvider->getCurrentExchangeRate('USD'));
        $this->assertEquals(0.5, $this->xrpPriceProvider->getCurrentExchangeRate('EUR'));
    }

    public function testCheckPricePlausibility(): void
    {
        $this->assertTrue($this->xrpPriceProvider->checkPricePlausibility(0.5));
        $this->assertFalse($this->xrpPriceProvider->checkPricePlausibility(0.0));
    }

    /**
     * Averages across Binance, Coingecko and Kraken, excluding an oracle
     * whose price diverges more than DEFAULT_ALLOWED_DIVERGENCE (5%) from
     * the average of all oracle results.
     */
    public function testDivergentOracleIsExcludedFromTheAverage(): void
    {
        $this->stubHttpResponsesByHost([
            'binance.com' => '{"price": 0.50}',
            'coingecko.com' => '{"ripple":{"usd":0.50}}',
            'kraken.com' => '{"result":{"XXRPZUSD":{"c":["0.55"]}}}',
        ]);
        $provider = new XrpPriceProvider();

        // Kraken's 0.55 diverges >5% from the 3-oracle average and is
        // dropped; the remaining Binance/Coingecko prices (0.50, 0.50) average to 0.50.
        $this->assertSame(0.5, $provider->getCurrentExchangeRate('USD'));
    }

    /**
     * A single oracle throwing must not abort the whole average (W3): the
     * exception is logged and the remaining oracles are still used.
     */
    public function testOracleExceptionIsLoggedAndDoesNotAbortTheAverage(): void
    {
        $this->stubHttpResponsesByHost([
            'binance.com' => new WP_Error('http_request_failed', 'connection failed'),
            'coingecko.com' => '{"ripple":{"usd":0.50}}',
            'kraken.com' => '{"result":{"XXRPZUSD":{"c":["0.50"]}}}',
        ]);
        $provider = new XrpPriceProvider();

        $this->assertSame(0.5, $provider->getCurrentExchangeRate('USD'));
    }
}
