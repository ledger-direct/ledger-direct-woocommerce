<?php

defined( 'ABSPATH' ) || exit();

class LedgerDirect
{

    public static $_instance;

    public static function instance(): LedgerDirect
    {
        if (self::$_instance == null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function __construct() {
        $this->load_dependencies();
        add_action( 'plugins_loaded', array( $this, 'plugins_loaded'), 10);
        add_action( 'admin_init', array( $this, 'admin_init'));
    }

    public function load_dependencies(): void {
        // Storefront stuff
        if ( is_admin() ) {
            // Admin stuff
        }
    }

    public function plugins_loaded() {

    }
    public function admin_init(): void {
        add_submenu_page(
            'woocommerce',
            __('LedgerDirect', 'woocommerce-ledger-direct'),
            __('LedgerDirect', 'woocommerce-ledger-direct'),
            'manage_woocommerce',
            admin_url('admin.php?page=wc-settings&tab=checkout&section=ledger-direct'),
            //'slug',
            [__CLASS__, 'settings_callback']
        );
        //include_once('partials/xumm-for-woocommerce-admin-display.php');
        include_once('admin/class-ledger-direct-admin-menus.php');
    }

    public function settings_callback(): void {
?>
        <h1>TEST</h1>";
        <?php
    }
}