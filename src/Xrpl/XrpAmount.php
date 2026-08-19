<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Xrpl;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;

/**
 * Minimal replacement for xrpl_php's Sugar\dropsToXrp().
 *
 * Drops are always whole numbers and 1 XRP is always exactly 1,000,000
 * drops, so converting is an exact decimal-point shift - no arbitrary-
 * precision math library (bcmath/gmp/brick-math) is needed to do this
 * without floating-point rounding error.
 */
class XrpAmount
{
    private const DROPS_PER_XRP_SCALE = 6;

    /**
     * @param string $drops
     * @return string
     * @throws Exception
     */
    public static function dropsToXrp(string $drops): string
    {
        if (!preg_match('/^-?[0-9]+$/', $drops)) {
            throw new Exception('dropsToXrp: failed sanity check - value "' . esc_html($drops) . '" does not match (^-?[0-9]+$).');
        }

        $negative = str_starts_with($drops, '-');
        $digits = $negative ? substr($drops, 1) : $drops;
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
        }

        $digits = str_pad($digits, self::DROPS_PER_XRP_SCALE + 1, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($digits, 0, -self::DROPS_PER_XRP_SCALE), '0');
        if ($whole === '') {
            $whole = '0';
        }
        $fraction = rtrim(substr($digits, -self::DROPS_PER_XRP_SCALE), '0');

        $result = $whole . ($fraction !== '' ? '.' . $fraction : '');

        return ($negative && $result !== '0') ? '-' . $result : $result;
    }
}
