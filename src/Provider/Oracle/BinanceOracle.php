<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider\Oracle;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;

class BinanceOracle implements OracleInterface
{
    /**
     * Get the current exchange rate for a currency pair from Binance.
     *
     * @param string $code1 Currency code for the first currency (e.g., 'XRP').
     * @param string $code2 Currency code for the second currency (e.g., 'USD').
     * @return float Current price of the currency pair.
     * @throws Exception
     */
    public function getCurrentPriceForPair(string $code1, string $code2): float
    {
        $symbol = $code1 . (($code2 === 'USD') ? 'USDT' : $code2);
        $url = 'https://api.binance.com/api/v3/ticker/price?symbol=' . $symbol;

        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            throw new Exception(esc_html($response->get_error_message()));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($data['price'])) {
            return (float) $data['price'];
        }

        return 0.0;
    }
}
