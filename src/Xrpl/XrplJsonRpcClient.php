<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Xrpl;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;

/**
 * Minimal JSON-RPC client for the one XRPL call this plugin makes
 * (account_tx), built on WordPress' own HTTP API instead of xrpl_php's
 * JsonRpcClient (which pulls in Guzzle plus xrpl_php's full dependency
 * tree - including ext-bcmath - for a single POST request this plugin
 * never needed the rest of the package for).
 *
 * @see https://xrpl.org/docs/references/http-websocket-apis/public-api-methods/account-methods/account_tx
 */
class XrplJsonRpcClient
{
    private const TIMEOUT_SECONDS = 10;

    private string $connectionUrl;

    public function __construct(string $connectionUrl)
    {
        $this->connectionUrl = $connectionUrl;
    }

    /**
     * Calls the account_tx JSON-RPC method and returns the raw `result` object.
     *
     * @param string $account
     * @param int|null $ledgerIndexMin
     * @param array|null $marker Opaque pagination marker from a previous response.
     * @return array
     * @throws Exception On any transport failure or XRPL-reported error.
     */
    public function accountTx(string $account, ?int $ledgerIndexMin = null, ?array $marker = null): array
    {
        $params = ['account' => $account];

        if ($ledgerIndexMin !== null) {
            $params['ledger_index_min'] = $ledgerIndexMin;
        }

        if ($marker !== null) {
            $params['marker'] = $marker;
        }

        $body = wp_json_encode([
            'method' => 'account_tx',
            'params' => [$params],
        ]);

        $response = wp_remote_post($this->connectionUrl, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        if (is_wp_error($response)) {
            throw new Exception('XRPL request failed: ' . esc_html($response->get_error_message()));
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $payload = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode !== 200 || !is_array($payload)) {
            throw new Exception('XRPL request failed with HTTP status ' . esc_html((string) $statusCode));
        }

        $result = $payload['result'] ?? null;

        if (!is_array($result)) {
            throw new Exception('XRPL response did not contain a result object.');
        }

        if (isset($result['error'])) {
            throw new Exception('XRPL error: ' . esc_html($result['error']) . (isset($result['error_message']) ? ' - ' . esc_html($result['error_message']) : ''));
        }

        return $result;
    }
}
