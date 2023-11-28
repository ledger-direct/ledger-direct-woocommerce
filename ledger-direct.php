<?php
/**
 * Plugin Name: LedgerDirect for WooCommerce
 * Plugin URI: https://www.ledger-direct.com
 * Description: A XRP Ledger integration.
 * Version: 0.0.1
 * Author: Alexander Busse | Hardcastle Technologies
 * Author URI: https://hardcastle.technology
 * Text Domain: ledger-direct
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 8.1
 *
 * @package LedgerDirect
 */

defined( 'ABSPATH' ) || exit;

use \DI\Container;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;

define( 'WC_LEDGER_DIRECT_PLUGIN_FILE_PATH', plugin_dir_path( __FILE__ ) );

require_once WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'vendor/autoload.php';
require_once WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/class-ledger-direct-install.php';
require_once WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/class-ledger-direct.php';

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

}
register_deactivation_hook( __FILE__, 'ld_deactivate' );

/**
 * Plugin deactivation hook.
 */
function ld_uninstall(): void {

}
register_uninstall_hook(__FILE__, 'ld_uninstall');

/**
 * Returns the DI container with interfaces wired up.
 *
 * @return \DI\Container
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
 * @return string
 */
function ld_get_svg_html(string $icon): string {
    if (!ctype_alnum($icon)) {
        die('Forbidden!');
    }

    return file_get_contents(WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/partials/' . $icon . '_svg.html');
}

LedgerDirect::instance();