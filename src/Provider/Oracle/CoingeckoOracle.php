<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider\Oracle;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;

class CoingeckoOracle implements OracleInterface
{
    /**
     * Fetches the current price for a given pair using Coingecko API.
     *
     * @param string $code1 Base currency (e.g., XRP).
     * @param string $code2 Quote currency (e.g., USD).
     * @return float
     * @throws Exception
     */
    public function getCurrentPriceForPair(string $code1, string $code2): float
    {
        $code1 = $this->mapCurrencyCode($code1);
        $code2 = $this->mapCurrencyCode($code2);

        $url = 'https://api.coingecko.com/api/v3/simple/price?ids=' . strtolower($code1) . '&vs_currencies=' . strtolower($code2);

        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            throw new Exception(esc_html($response->get_error_message()));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($data[strtolower($code1)][strtolower($code2)])) {
            return (float) $data[strtolower($code1)][strtolower($code2)];
        }

        return 0.0;
    }

    /**
     * Maps currency codes to Coingecko's expected format.
     *
     * @param string $currencyCode The currency code to map.
     * @return string Mapped currency code.
     */
    private function mapCurrencyCode(string $currencyCode): string
    {
        $mappings = [
            'BTC' => 'bitcoin',
            'ETH' => 'ethereum',
            'USDT' => 'tether',
            'XRP' => 'ripple',
            'USDC' => 'usd-coin',
            'RLUSD' => 'ripple-usd',
        ];

        return $mappings[$currencyCode] ?? strtolower($currencyCode);
    }
}
