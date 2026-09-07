<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Xrpl\Stablecoin;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;

/**
 * Minimal replacement for xrpl_php's Core\Stablecoin\RLUSD: the issuer and
 * currency code are public, well-known XRPL constants, not something
 * xrpl_php computes - it's a static lookup table.
 */
class RLUSD
{
    private const SETTINGS = [
        'mainnet' => [
            'issuer' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
            'currency' => '524C555344000000000000000000000000000000',
        ],
        'testnet' => [
            'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV',
            'currency' => '524C555344000000000000000000000000000000',
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
            throw new Exception('RLUSD not available for network: ' . esc_html($network));
        }

        return [
            'currency' => self::SETTINGS[$network]['currency'],
            'issuer' => self::SETTINGS[$network]['issuer'],
            'value' => $value,
        ];
    }
}
