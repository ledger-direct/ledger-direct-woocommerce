<?php

defined( 'ABSPATH' ) || exit();

use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;

class LedgerDirect
{
    public static $_instance;

    public static function instance(): self
    {
        if (self::$_instance == null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function __construct() {
        $this->load_dependencies();

        $this->public_hooks();
        if ( is_admin() ) {
            $this->admin_hooks();
        }
    }

    public function load_dependencies(): void {
        require_once WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/admin/class-ledger-direct-admin.php';

        if (class_exists('WooCommerce')) {
            LedgerDirectPaymentGateway::instance();
        }
    }

    public function public_hooks(): void {
        add_filter( 'woocommerce_payment_gateways', [$this, 'register_gateway']);
        add_action( 'template_redirect', [$this, 'render_payment_page'] );
    }

    public function admin_hooks(): void {
        $classAdmin = new LedgerDirectAdmin();

        add_action( 'plugins_loaded', [$this, 'plugins_loaded_callback'], 10);
        add_action( 'admin_menu', [$this, 'admin_menu_callback']);

        add_filter('ledger_direct_init_form_fields', [$classAdmin, 'init_form_fields'], 10, 1);
        add_filter('ledger_direct_render_plugin_settings', [$classAdmin, 'render_plugin_settings'], 10, 1);
    }

    public function plugins_loaded_callback() {
        if (class_exists('WooCommerce')) {
            // Initialize Ledger Direct Gateway
            LedgerDirectPaymentGateway::instance();
        }
    }

    public function admin_menu_callback(): void {
         add_submenu_page(
            'woocommerce',
            __('LedgerDirect', 'woocommerce-ledger-direct'),
            __('LedgerDirect', 'woocommerce-ledger-direct'),
            'manage_woocommerce',
             admin_url('admin.php?page=wc-settings&tab=checkout&section=ledger-direct'),
            null
        );
    }

    /**
     * Register LedgerDirect as WooCommerce Payment Gateway
     *
     * @param $gateways
     * @return array
     */
    public function register_gateway($gateways): array {
        $gateways[] = 'Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway';

        return $gateways;
    }

    /**
     * Replace template with custom payment page
     *
     * @return void
     */
    public function render_payment_page(): void {
        $page_id = get_the_ID();
        $post_name = get_post_field('post_name');
        if ($post_name === 'ledger-direct') { // TODO: Replace hardcoded value
            require_once WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/views/ledger-direct_html.php';
            exit();
        }
    }
}