<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Http;

use Hardcastle\LedgerDirect\Http\WpHttpClient;
use Hardcastle\LedgerDirect\Http\WpHttpException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use WP_Error;

class WpHttpClientTest extends TestCase
{
    /** @var callable|null */
    private $filter = null;

    protected function tearDown(): void
    {
        if ($this->filter !== null) {
            remove_filter('pre_http_request', $this->filter, 10);
        }

        parent::tearDown();
    }

    private function stubNetwork(callable $handler): void
    {
        $this->filter = $handler;
        add_filter('pre_http_request', $handler, 10, 3);
    }

    public function testTranslatesRequestAndResponse(): void
    {
        $captured = [];

        $this->stubNetwork(function ($preempt, array $args, string $url) use (&$captured) {
            $captured = ['args' => $args, 'url' => $url];

            return [
                'headers' => ['content-type' => 'application/json'],
                'body' => '{"result":"ok"}',
                'response' => ['code' => 201, 'message' => 'Created'],
                'cookies' => [],
                'filename' => null,
            ];
        });

        $factory = new Psr17Factory();
        $request = $factory->createRequest('POST', 'https://example.test/rpc')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"method":"tx"}'));

        $response = (new WpHttpClient())->sendRequest($request);

        $this->assertSame('https://example.test/rpc', $captured['url']);
        $this->assertSame('POST', $captured['args']['method']);
        $this->assertSame('{"method":"tx"}', $captured['args']['body']);
        $this->assertSame('application/json', $captured['args']['headers']['Content-Type']);
        $this->assertTrue($captured['args']['sslverify']);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('{"result":"ok"}', (string) $response->getBody());
        $this->assertSame('application/json', $response->getHeaderLine('content-type'));
    }

    public function testNetworkFailureBecomesAPsr18NetworkException(): void
    {
        $this->stubNetwork(fn () => new WP_Error('http_request_failed', 'cURL error 28: timed out'));

        $request = (new Psr17Factory())->createRequest('GET', 'https://example.test/price');

        try {
            (new WpHttpClient())->sendRequest($request);
            $this->fail('Expected a WpHttpException');
        } catch (WpHttpException $exception) {
            $this->assertSame('cURL error 28: timed out', $exception->getMessage());
            $this->assertSame($request, $exception->getRequest());
        }
    }
}
