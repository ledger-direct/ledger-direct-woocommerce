<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider\Oracle;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Stream;
use Hardcastle\LedgerDirect\Provider\Oracle\CoingeckoOracle;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class CoingeckoOracleTest extends TestCase
{
    private function mockClientReturning(string $json): Client
    {
        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getBody')
            ->andReturn(new Stream(fopen('data://text/plain,' . $json, 'r')));

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->andReturn($response);

        return $client;
    }

    public function testMapsKnownCurrencyCodesToCoingeckoIds(): void
    {
        $client = $this->mockClientReturning('{"ripple":{"usd":0.6}}');
        $oracle = (new CoingeckoOracle())->prepare($client);

        $this->assertSame(0.6, $oracle->getCurrentPriceForPair('XRP', 'USD'));
    }

    public function testMapsRlusdAndUsdc(): void
    {
        $client = $this->mockClientReturning('{"ripple-usd":{"eur":0.92}}');
        $oracle = (new CoingeckoOracle())->prepare($client);
        $this->assertSame(0.92, $oracle->getCurrentPriceForPair('RLUSD', 'EUR'));

        $client = $this->mockClientReturning('{"usd-coin":{"eur":0.91}}');
        $oracle = (new CoingeckoOracle())->prepare($client);
        $this->assertSame(0.91, $oracle->getCurrentPriceForPair('USDC', 'EUR'));
    }

    public function testUnmappedCodeFallsBackToLowercasedCode(): void
    {
        // Not in the mapping table (e.g. EURC, see W7) - falls back to
        // strtolower($code), which will not match a real Coingecko id.
        $client = $this->mockClientReturning('{}');
        $oracle = (new CoingeckoOracle())->prepare($client);

        $this->assertSame(0.0, $oracle->getCurrentPriceForPair('XRP', 'EURC'));
    }
}
