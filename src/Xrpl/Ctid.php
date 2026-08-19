<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Xrpl;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Minimal replacement for xrpl_php's Core\Ctid, covering only the one
 * operation this plugin uses: building the hex Concise Transaction
 * Identifier from its raw components.
 *
 * @see https://github.com/XRPLF/XRPL-Standards/discussions/91
 */
class Ctid
{
    private const FILLER = 0xc0000000;

    /**
     * @param int $ledgerIndex
     * @param int $transactionIndex
     * @param int $networkId
     * @return string 16-character uppercase hex CTID.
     */
    public static function toHex(int $ledgerIndex, int $transactionIndex, int $networkId): string
    {
        $ledgerIndexHex = dechex(self::FILLER + $ledgerIndex);
        $transactionIndexHex = str_pad(dechex($transactionIndex), 4, '0', STR_PAD_LEFT);
        $networkIdHex = str_pad(dechex($networkId), 4, '0', STR_PAD_LEFT);

        return strtoupper($ledgerIndexHex . $transactionIndexHex . $networkIdHex);
    }
}
