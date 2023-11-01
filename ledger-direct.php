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

define( 'WC_LEDGER_DIRECT_PLUGIN_FILE_PATH', plugin_dir_path( __FILE__ ) );

require_once WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'vendor/autoload.php';
require_once WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/class-ledger-direct-install.php';
require_once WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/class-ledger-direct.php';

/**
 * Plugin deactivation hook.
 */
function ledger_direct_activate() {
    LedgerDirectInstall::install();
}
register_activation_hook( __FILE__, 'ledger_direct_activate' );

/**
 * Plugin deactivation hook.
 */
function ledger_direct_deactivate() {

}
register_deactivation_hook( __FILE__, 'ledger_direct_deactivate' );

/**
 * Plugin deactivation hook.
 */
function ledger_direct_uninstall() {

}
register_uninstall_hook(__FILE__, 'ledger_direct_uninstall');

function run_ledger_direct(): LedgerDirect {
    return LedgerDirect::instance();
}

run_ledger_direct();