<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider\Oracle;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Stream;
use Hardcastle\LedgerDirect\Provider\Oracle\KrakenOracle;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class KrakenOracleTest extends TestCase
{
    private function mockClientWithBody(string $json): Client
    {
        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getBody')
            ->andReturn(new Stream(fopen('data://text/plain,' . $json, 'r')));

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->andReturn($response);

        return $client;
    }

    /**
     * Regression test for W4: KrakenOracle used to read a hardcoded
     * 'XXRPZUSD' key, so every pair other than XRP/USD silently returned 0.0.
     */
    public function testReadsPriceRegardlessOfKrakenPairKey(): void
    {
        $client = $this->mockClientWithBody('{"error":[],"result":{"XXRPZEUR":{"c":["0.75","10.0"]}}}');
        $oracle = (new KrakenOracle())->prepare($client);

        $this->assertSame(0.75, $oracle->getCurrentPriceForPair('XRP', 'EUR'));
    }

    public function testStillReadsTheOriginalXrpUsdPairKey(): void
    {
        $client = $this->mockClientWithBody('{"error":[],"result":{"XXRPZUSD":{"c":["0.5","10.0"]}}}');
        $oracle = (new KrakenOracle())->prepare($client);

        $this->assertSame(0.5, $oracle->getCurrentPriceForPair('XRP', 'USD'));
    }

    public function testReturnsZeroWhenResultIsEmpty(): void
    {
        $client = $this->mockClientWithBody('{"error":["EQuery:Unknown asset pair"],"result":{}}');
        $oracle = (new KrakenOracle())->prepare($client);

        $this->assertSame(0.0, $oracle->getCurrentPriceForPair('XRP', 'ZZZ'));
    }
}
