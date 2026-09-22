<?php declare(strict_types=1);
/**
 * Plugin Name: Ledger Direct
 * Plugin URI: https://github.com/ledger-direct/ledger-direct-woocommerce
 * Description: A XRP Ledger integration.
 * Version: 1.2.0
 * Author: Alexander Busse | Hardcastle Technologies
 * Author URI: https://www.ledger-direct.com
 * Text Domain: ledger-direct
 * Domain Path: /languages
 * Requires at least: 6.7
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * WC requires at least: 8.6.1
 * WC tested up to: 11.1
 * License: MIT
 *
 * @package LedgerDirect
 */

defined( 'ABSPATH' ) || exit;

use Hardcastle\LedgerDirect\Api\PaymentStatusEndpoint;
use Hardcastle\LedgerDirect\Cron\SettlePendingOrders;
use Hardcastle\LedgerDirect\Port\WpConfigProvider;
use Hardcastle\LedgerDirect\Service\ServiceFactory;

define( 'LEDGER_DIRECT_PLUGIN_FILE_PATH', plugin_dir_path( __FILE__ ) );

/**
 * WordPress refuses to update or activate the plugin on an older PHP via
 * the "Requires PHP" header, but a manual upload bypasses that - and the
 * bundled dependencies would then fatal inside the autoloader. Bow out
 * with a notice instead.
 */
if ( PHP_VERSION_ID < 80200 ) {
    add_action( 'admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' .
            esc_html__( 'Ledger Direct requires PHP 8.2 or newer and has been disabled on this site.', 'ledger-direct' ) .
            '</p></div>';
    } );

    return;
}

require_once LEDGER_DIRECT_PLUGIN_FILE_PATH . 'vendor/autoload.php';
require_once LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/class-ledger-direct-install.php';
require_once LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/class-ledger-direct.php';

/**
 * Plugin activation hook.
 */
function ledger_direct_activate(): void {
    LedgerDirectInstall::install();
}
register_activation_hook( __FILE__, 'ledger_direct_activate');

/**
 * Run outstanding DB migrations on already-active installs.
 *
 * WordPress does not re-fire the activation hook on a routine plugin
 * update, so this is the only reliable place to migrate sites that were
 * already live before a schema change ships.
 */
add_action( 'plugins_loaded', ['LedgerDirectInstall', 'maybe_upgrade'] );

/**
 * Plugin deactivation hook.
 */
function ledger_direct_deactivate(): void {
    LedgerDirectInstall::deactivate();
}
register_deactivation_hook( __FILE__, 'ledger_direct_deactivate');

/**
 * Plugin uninstall hook.
 */
function ledger_direct_uninstall(): void {
    LedgerDirectInstall::uninstall();
}
register_uninstall_hook(__FILE__, 'ledger_direct_uninstall');

/**
 * The merchant's configuration as the gateway and checkout need it.
 *
 * Network, receiving account, quote expiry and the per-asset switches are
 * read through the core's config port (WpConfigProvider); this only adds
 * the gateway's own "enabled" flag and the derived availability flags.
 *
 * @return array
 */
function ledger_direct_get_configuration(): array {
    $settings = get_option(WpConfigProvider::OPTION_NAME, []);
    $settings = is_array($settings) ? $settings : [];

    $config = ServiceFactory::getInstance()->getConfigProvider();
    $wallet_available = $config->hasDestinationAccount();

    return [
        'enabled' => $settings['enabled'] ?? 'no',
        'xrpl_network' => $config->getNetwork(WpConfigProvider::CHAIN),
        'wallet_available' => $wallet_available,
        'destination_account' => $config->getDestinationAccount(WpConfigProvider::CHAIN),
        'rlusd_available' => $wallet_available && $config->isAssetEnabled(WpConfigProvider::CHAIN, 'RLUSD'),
        'usdc_available' => $wallet_available && $config->isAssetEnabled(WpConfigProvider::CHAIN, 'USDC'),
        'quote_expiry_seconds' => $config->getQuoteExpirySeconds(),
    ];
}

/**
 * Get the plugin url.
 *
 * @param string $url
 * @return string
 */
function ledger_direct_get_public_url(string $url): string {
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
function ledger_direct_get_svg_html(string $icon, array $properties = []): string {
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

SettlePendingOrders::register();
PaymentStatusEndpoint::register();

LedgerDirect::instance();
