<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider\Oracle;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;

class KrakenOracle implements OracleInterface
{
    /**
     * Fetches the current price for a currency pair from Kraken.
     *
     * @param string $code1 Base currency code (e.g., 'XRP').
     * @param string $code2 Quote currency code (e.g., 'USD').
     * @return float Current price of the currency pair.
     * @throws Exception
     */
    public function getCurrentPriceForPair(string $code1, string $code2): float
    {
        $pair = $code1 . $code2;
        $url = 'https://api.kraken.com/0/public/Ticker?pair=' . $pair;

        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            throw new Exception(esc_html($response->get_error_message()));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        // Kraken renames requested pairs (e.g. 'XRPUSD' -> 'XXRPZUSD'), so read
        // the single entry under `result` generically instead of a fixed key.
        $result = $data['result'] ?? [];
        $ticker = is_array($result) ? reset($result) : false;

        if (is_array($ticker) && isset($ticker['c'][0])) {
            return (float) $ticker['c'][0];
        }

        return 0.0;
    }
}
