<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider\Oracle;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;

class CoinbaseOracle implements OracleInterface
{
    /**
     * Get the current exchange rate for a currency pair from Coinbase.
     *
     * @param string $code1 Base currency code (e.g., 'XRP').
     * @param string $code2 Quote currency code (e.g., 'USD').
     * @return float Current price of the currency pair.
     * @throws Exception
     */
    public function getCurrentPriceForPair(string $code1, string $code2): float
    {
        $url = 'https://api.coinbase.com/v2/prices/' . strtoupper($code1) . '-' . strtoupper($code2) . '/spot';

        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            throw new Exception(esc_html($response->get_error_message()));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($data['data']['amount'])) {
            return (float) $data['data']['amount'];
        }

        return 0.0;
    }
}
