<?php declare(strict_types=1);
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
        'class' => '',
        'width' => '24',
        'height' => '24',
        'viewBox' => '0 0 24 24',
    ];

    $svgContent = file_get_contents(WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/partials/' . $icon . '_svg.html');

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

function calculate_token_order_total(WC_Order $order): float
{
    $total = 0.0;
    foreach ($order->get_items() as $item_id => $item) {
        $product_id = $item->get_product_id();
        $product = wc_get_product($product_id);
        $lpt_price = $product->get_meta('_ledger_direct_lpt_price');

        // Get the product quantity
        $quantity = $item->get_quantity();

        // Do something with these values...
        $total += $lpt_price * $quantity;
    }

    return $total;
}

function ld_round_stable_coin(float $value): float
{
    // Round to 2 decimal places for stable coins
    return round($value, 2);
}

LedgerDirect::instance();