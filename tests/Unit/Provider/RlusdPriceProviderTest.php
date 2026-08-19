<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Stream;
use Hardcastle\LedgerDirect\Provider\RlusdPriceProvider;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class RlusdPriceProviderTest extends TestCase
{
    private function providerWithClientBody(string $json): RlusdPriceProvider
    {
        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getBody')
            ->andReturn(new Stream(fopen('data://text/plain,' . $json, 'r')));

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->andReturn($response);

        return new RlusdPriceProvider($client);
    }

    public function testUsdIsPeggedWithoutCallingAnyOracle(): void
    {
        // RLUSD is pegged 1:1 to USD; the client is never given a stubbed
        // response, so a call to $client->get() here would blow up the test.
        $client = Mockery::mock(Client::class);
        $provider = new RlusdPriceProvider($client);

        $this->assertSame(1.0, $provider->getCurrentExchangeRate('USD'));
    }

    public function testNonUsdCurrencyIsResolvedViaOracle(): void
    {
        $provider = $this->providerWithClientBody('{"ripple-usd":{"eur":0.93}}');

        $this->assertSame(0.93, $provider->getCurrentExchangeRate('EUR'));
    }

    public function testCheckPricePlausibility(): void
    {
        $provider = new RlusdPriceProvider(Mockery::mock(Client::class));

        $this->assertTrue($provider->checkPricePlausibility(1.0));
        $this->assertFalse($provider->checkPricePlausibility(0.0));
    }
}
