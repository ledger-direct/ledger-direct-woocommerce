<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Stream;
use Hardcastle\LedgerDirect\Provider\UsdcPriceProvider;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class UsdcPriceProviderTest extends TestCase
{
    private function providerWithClientBody(string $json): UsdcPriceProvider
    {
        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getBody')
            ->andReturn(new Stream(fopen('data://text/plain,' . $json, 'r')));

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->andReturn($response);

        return new UsdcPriceProvider($client);
    }

    public function testUsdIsPeggedWithoutCallingAnyOracle(): void
    {
        $client = Mockery::mock(Client::class);
        $provider = new UsdcPriceProvider($client);

        $this->assertSame(1.0, $provider->getCurrentExchangeRate('USD'));
    }

    public function testNonUsdCurrencyIsResolvedViaOracle(): void
    {
        $provider = $this->providerWithClientBody('{"usd-coin":{"eur":0.91}}');

        $this->assertSame(0.91, $provider->getCurrentExchangeRate('EUR'));
    }

    public function testCheckPricePlausibility(): void
    {
        $provider = new UsdcPriceProvider(Mockery::mock(Client::class));

        $this->assertTrue($provider->checkPricePlausibility(1.0));
        $this->assertFalse($provider->checkPricePlausibility(0.0));
    }
}
