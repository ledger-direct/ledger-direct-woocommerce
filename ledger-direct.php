<?php declare(strict_types=1);
/**
 * Plugin Name: Ledger Direct
 * Plugin URI: https://github.com/ledger-direct/ledger-direct-woocommerce
 * Description: A XRP Ledger integration.
 * Version: 0.10.1
 * Author: Alexander Busse | Hardcastle Technologies
 * Author URI: https://www.ledger-direct.com
 * Text Domain: ledger-direct
 * Domain Path: /languages
 * Requires at least: 6.7
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
function ledger_direct_activate(): void {
    LedgerDirectInstall::install();
}
register_activation_hook( __FILE__, 'ledger_direct_activate');

/**
 * Plugin deactivation hook.
 */
function edger_direct_deactivate(): void {
    LedgerDirectInstall::deactivate();
}
register_deactivation_hook( __FILE__, 'edger_direct_deactivate');

/**
 * Plugin deactivation hook.
 */
function ledger_direct_uninstall(): void {
    LedgerDirectInstall::uninstall();
}
register_uninstall_hook(__FILE__, 'ledger_direct_uninstall');

function ledger_direct_get_configuration(): array {
    $settings = get_option('woocommerce_ledger-direct_settings', []);

    $preparedSettings = [
        'enabled' => $settings['enabled'] ?? 'no',
    ];

    $xrpl_network = in_array($settings['xrpl_network'], ['mainnet', 'testnet']) ? $settings['xrpl_network'] : 'testnet';

    $xrpl_testnet_destination_account = $settings['xrpl_testnet_destination_account'] ?? '';
    $xrpl_mainnet_destination_account = $settings['xrpl_mainnet_destination_account'] ?? '';

    $xrpl_account_regex = '/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/';
    $testnet_wallet_available = !empty($xrpl_testnet_destination_account) && preg_match($xrpl_account_regex, $xrpl_testnet_destination_account);
    $mainnet_wallet_available = !empty($xrpl_mainnet_destination_account) && preg_match($xrpl_account_regex, $xrpl_mainnet_destination_account);

    $rlusd_available = isset($settings['xrpl_is_rlusd_enabled']) && $settings['xrpl_is_rlusd_enabled'] === 'yes';
    $usdc_available = isset($settings['xrpl_is_usdc_enabled']) && $settings['xrpl_is_usdc_enabled'] === 'yes';

    $testnet_rlusd_available = $rlusd_available && $testnet_wallet_available;
    $mainnet_rlusd_available = $usdc_available && $mainnet_wallet_available;
    $testnet_usdc_available = $rlusd_available && $testnet_wallet_available;
    $mainnet_usdc_available = $usdc_available && $mainnet_wallet_available;

    $order_expiry = isset($settings['xrpl_quote_expiry']) && is_numeric($settings['xrpl_quote_expiry']) ? (int)$settings['xrpl_quote_expiry'] : 15;

    if ($xrpl_network === 'mainnet') {
        $preparedSettings['xrpl_network'] = 'mainnet';
        $preparedSettings['wallet_available'] = $mainnet_wallet_available;
        $preparedSettings['destination_account'] = $xrpl_mainnet_destination_account;
        $preparedSettings['rlusd_available'] = $mainnet_rlusd_available;
        $preparedSettings['usdc_available'] = $mainnet_usdc_available;
        $preparedSettings['order_expiry'] = $order_expiry;
    } else {
        $preparedSettings['xrpl_network'] = 'testnet';
        $preparedSettings['wallet_available'] = $testnet_wallet_available;
        $preparedSettings['destination_account'] = $xrpl_testnet_destination_account;
        $preparedSettings['rlusd_available'] = $testnet_rlusd_available;
        $preparedSettings['usdc_available'] = $testnet_usdc_available;
        $preparedSettings['order_expiry'] = $order_expiry;
    }

    return $preparedSettings;
}

/**
 * Returns the DI container with interfaces wired up.
 *
 * @return Container
 */
function ledger_direct_get_dependency_injection_container(): Container {
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

/**
 * Round stablecoin values to 2 decimal places.
 *
 * @param float|int $value
 * @return float
 */
function ledger_direct_round_stable_coin(float|int $value): float
{
    return round($value, 2, PHP_ROUND_HALF_UP);
}

LedgerDirect::instance();