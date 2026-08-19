<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Stream;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class XrpPriceProviderTest extends TestCase
{
    private XrpPriceProvider $xrpPriceProvider;

    private Client $client;

    protected function setUp(): void
    {
        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getBody')
            ->andReturn(new Stream(fopen('data://text/plain,' . '{"price": 0.5}','r')));

        $this->client = Mockery::mock(Client::class);
        $this->client->shouldReceive('get')
            ->andReturn($response);

        $this->xrpPriceProvider = new XrpPriceProvider($this->client);
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
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')
            ->with(Mockery::on(fn ($url) => str_contains($url, 'binance.com')))
            ->andReturn($this->jsonResponse('{"price":"0.50"}'));
        $client->shouldReceive('get')
            ->with(Mockery::on(fn ($url) => str_contains($url, 'coingecko.com')))
            ->andReturn($this->jsonResponse('{"ripple":{"usd":0.50}}'));
        $client->shouldReceive('get')
            ->with(Mockery::on(fn ($url) => str_contains($url, 'kraken.com')))
            ->andReturn($this->jsonResponse('{"result":{"XXRPZUSD":{"c":["0.55"]}}}'));

        $provider = new XrpPriceProvider($client);

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
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')
            ->with(Mockery::on(fn ($url) => str_contains($url, 'binance.com')))
            ->andThrow(new \Exception('connection failed'));
        $client->shouldReceive('get')
            ->with(Mockery::on(fn ($url) => str_contains($url, 'coingecko.com')))
            ->andReturn($this->jsonResponse('{"ripple":{"usd":0.50}}'));
        $client->shouldReceive('get')
            ->with(Mockery::on(fn ($url) => str_contains($url, 'kraken.com')))
            ->andReturn($this->jsonResponse('{"result":{"XXRPZUSD":{"c":["0.50"]}}}'));

        $provider = new XrpPriceProvider($client);

        $this->assertSame(0.5, $provider->getCurrentExchangeRate('USD'));
    }

    private function jsonResponse(string $json): ResponseInterface
    {
        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getBody')
            ->andReturn(new Stream(fopen('data://text/plain,' . $json, 'r')));

        return $response;
    }
}