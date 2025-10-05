<?php declare(strict_types=1);
/**
 * Plugin Name: Ledger Direct
 * Plugin URI: https://www.ledger-direct.com
 * Description: A XRP Ledger integration.
 * Version: 0.8.0
 * Author: Alexander Busse | Hardcastle Technologies
 * Author URI: https://www.ledger-direct.com
 * Text Domain: ledger-direct
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * License: MIT
 *
 * @package LedgerDirect
 */

defined( 'ABSPATH' ) || exit;

use \DI\Container;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;

define( 'LEDGER_DIRECT_PLUGIN_FILE_PATH', plugin_dir_path( __FILE__ ) );

require_once LEDGER_DIRECT_PLUGIN_FILE_PATH . 'vendor/autoload.php';
require_once LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/class-ledger-direct-install.php';
require_once LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/class-ledger-direct.php';

/**
 * Plugin deactivation hook.
 */
function ld_activate(): void {
    LedgerDirectInstall::install();
}
register_activation_hook( __FILE__, 'ld_activate' );

/**
 * Plugin deactivation hook.
 */
function ld_deactivate(): void {
    LedgerDirectInstall::deactivate();
}
register_deactivation_hook( __FILE__, 'ld_deactivate' );

/**
 * Plugin deactivation hook.
 */
function ld_uninstall(): void {
    // LedgerDirectInstall::uninstall();
}
register_uninstall_hook(__FILE__, 'ld_uninstall');

/**
 * Returns the DI container with interfaces wired up.
 *
 * @return Container
 */
function ld_get_dependency_injection_container(): Container {
    return new Container([
        CryptoPriceProviderInterface::class => \DI\autowire(XrpPriceProvider::class),
    ]);
}

/**
 * Get the plugin url.
 *
 * @param string $url
 * @return string
 */
function ld_get_public_url(string $url): string {
    $base = plugins_url( '/', __FILE__ );
    return untrailingslashit($base . $url);
}

/**
 * Get SVG HTML for icon
 *
 * @param string $icon
 * @param array $properties
 * @return string
 */
function ld_get_svg_html(string $icon, array $properties = []): string {
    if (!ctype_alnum($icon)) {
        die('Forbidden!');
    }

    $defaultProperties = [
        'id' => $icon . '-icon',
        'class' => '',
        'width' => '24',
        'height' => '24',
        'viewBox' => '0 0 24 24',
    ];

    $svgContent = file_get_contents(LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/partials/' . $icon . '_svg.html');

    foreach ($defaultProperties as $key => $value) {
        if (isset($properties[$key])) {
            $defaultProperties[$key] = $properties[$key];
        }
    }

    foreach ($defaultProperties as $key => $value) {
        $svgContent = str_replace(
            '{' . $key . '}',
            $key . '="' . $value . '"',
            $svgContent
        );
    }

    return $svgContent;
}

/**
 * Round stablecoin values to 2 decimal places.
 *
 * @param float|int $value
 * @return float
 */
function ld_round_stable_coin(float|int $value): float
{
    return round($value, 2, PHP_ROUND_HALF_UP);
}

LedgerDirect::instance();