<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Xrpl\Stablecoin;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;

/**
 * Minimal replacement for xrpl_php's Core\Stablecoin\USDC: the issuer and
 * currency code are public, well-known XRPL constants, not something
 * xrpl_php computes - it's a static lookup table.
 */
class USDC
{
    private const SETTINGS = [
        'mainnet' => [
            'issuer' => 'rGm7WCVp9gb4jZHWTEtGUr4dd74z2XuWhE',
            'currency' => '5553444300000000000000000000000000000000',
        ],
        'testnet' => [
            'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt',
            'currency' => '5553444300000000000000000000000000000000',
        ],
    ];

    /**
     * @param string $network
     * @param string $value Already-rounded decimal amount as a string.
     * @return array{currency: string, issuer: string, value: string}
     * @throws Exception
     */
    public static function getAmount(string $network, string $value): array
    {
        if (!isset(self::SETTINGS[$network])) {
            throw new Exception('USDC not available for network: ' . esc_html($network));
        }

        return [
            'currency' => self::SETTINGS[$network]['currency'],
            'issuer' => self::SETTINGS[$network]['issuer'],
            'value' => $value,
        ];
    }
}
