<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Xrpl;

use Exception;
use Hardcastle\LedgerDirect\Xrpl\XrplJsonRpcClient;
use PHPUnit\Framework\TestCase;
use WP_Error;

class XrplJsonRpcClientTest extends TestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    public function testAccountTxBuildsTheExpectedJsonRpcRequestBody(): void
    {
        $capturedBody = null;
        add_filter('pre_http_request', function ($preempt, $parsedArgs) use (&$capturedBody) {
            $capturedBody = json_decode($parsedArgs['body'], true);

            return [
                'body' => wp_json_encode(['result' => ['transactions' => []]]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'headers' => [],
            ];
        }, 10, 3);

        $client = new XrplJsonRpcClient('https://example.test');
        $client->accountTx('rAccount', 12345, ['marker' => 'x']);

        $this->assertSame('account_tx', $capturedBody['method']);
        $this->assertSame('rAccount', $capturedBody['params'][0]['account']);
        $this->assertSame(12345, $capturedBody['params'][0]['ledger_index_min']);
        $this->assertSame(['marker' => 'x'], $capturedBody['params'][0]['marker']);
    }

    public function testAccountTxOmitsLedgerIndexMinAndMarkerWhenNull(): void
    {
        $capturedBody = null;
        add_filter('pre_http_request', function ($preempt, $parsedArgs) use (&$capturedBody) {
            $capturedBody = json_decode($parsedArgs['body'], true);

            return [
                'body' => wp_json_encode(['result' => ['transactions' => []]]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'headers' => [],
            ];
        }, 10, 3);

        $client = new XrplJsonRpcClient('https://example.test');
        $client->accountTx('rAccount');

        $this->assertArrayNotHasKey('ledger_index_min', $capturedBody['params'][0]);
        $this->assertArrayNotHasKey('marker', $capturedBody['params'][0]);
    }

    public function testAccountTxReturnsTheResultObjectOnSuccess(): void
    {
        add_filter('pre_http_request', function () {
            return [
                'body' => wp_json_encode(['result' => ['transactions' => [['tx' => ['hash' => 'ABC']]], 'status' => 'success']]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'headers' => [],
            ];
        }, 10, 3);

        $client = new XrplJsonRpcClient('https://example.test');
        $result = $client->accountTx('rAccount', -1);

        $this->assertSame('ABC', $result['transactions'][0]['tx']['hash']);
    }

    public function testAccountTxThrowsOnXrplLevelError(): void
    {
        add_filter('pre_http_request', function () {
            return [
                'body' => wp_json_encode(['result' => ['error' => 'actNotFound', 'error_message' => 'Account not found.', 'status' => 'error']]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'headers' => [],
            ];
        }, 10, 3);

        $client = new XrplJsonRpcClient('https://example.test');

        $this->expectException(Exception::class);
        $client->accountTx('rUnknownAccount');
    }

    public function testAccountTxThrowsOnTransportFailure(): void
    {
        add_filter('pre_http_request', function () {
            return new WP_Error('http_request_failed', 'Connection timed out');
        }, 10, 3);

        $client = new XrplJsonRpcClient('https://example.test');

        $this->expectException(Exception::class);
        $client->accountTx('rAccount');
    }

    public function testAccountTxThrowsOnNonSuccessHttpStatus(): void
    {
        add_filter('pre_http_request', function () {
            return [
                'body' => 'Internal Server Error',
                'response' => ['code' => 500, 'message' => 'Internal Server Error'],
                'headers' => [],
            ];
        }, 10, 3);

        $client = new XrplJsonRpcClient('https://example.test');

        $this->expectException(Exception::class);
        $client->accountTx('rAccount');
    }
}
