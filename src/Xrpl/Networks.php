<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Xrpl;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;

/**
 * Minimal replacement for xrpl_php's Core\Networks, covering only the
 * networks this plugin actually offers (see ledger_direct_get_configuration()).
 */
class Networks
{
    private const NETWORKS = [
        'mainnet' => [
            'label' => 'XRPL Mainnet',
            'jsonRpcUrl' => 'https://xrplcluster.com',
            'networkId' => 0,
        ],
        'testnet' => [
            'label' => 'XRPL Testnet',
            'jsonRpcUrl' => 'https://s.altnet.rippletest.net:51234',
            'networkId' => 1,
        ],
    ];

    /**
     * @param string $identifier
     * @return array{label: string, jsonRpcUrl: string, networkId: int}
     * @throws Exception
     */
    public static function get(string $identifier): array
    {
        if (isset(self::NETWORKS[$identifier])) {
            return self::NETWORKS[$identifier];
        }

        throw new Exception('Network not found: ' . esc_html($identifier));
    }
}
