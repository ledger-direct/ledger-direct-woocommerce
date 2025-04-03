<?php

defined( 'ABSPATH' ) || exit();

use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;

class LedgerDirect
{
    public  const PAYMENT_IDENTIFIER = 'order_id';

    public static self|null $_instance = null;

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

    /**
     * Load classes
     *
     * @return void
     */
    public function load_dependencies(): void {
        require_once WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/admin/class-ledger-direct-admin.php';

        if (class_exists('WooCommerce')) {
            LedgerDirectPaymentGateway::instance();
        }
    }

    /**
     * Register public actions and filters
     *
     * @return void
     */
    public function public_hooks(): void {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_filter('woocommerce_payment_gateways', [$this, 'register_gateway']);
        add_filter('woocommerce_get_price_html', [$this, 'custom_price_html']);
        add_filter('woocommerce_checkout_create_order', [$this, 'before_checkout_create_order'], 20, 2);
        add_filter('template_include', [$this, 'render_payment_page']);


        add_action( 'plugins_loaded', [$this, 'load_translations'] );
        add_action( 'wp_enqueue_scripts', [$this, 'enqueue_public_styles'] );
        add_action( 'wp_enqueue_scripts', [$this, 'enqueue_public_scripts'] );
    }

    /**
     * Register admin actions and filters
     *
     * @return void
     */
    public function admin_hooks(): void {
        $classAdmin = new LedgerDirectAdmin();

        add_action('plugins_loaded', [$this, 'plugins_loaded_callback'], 10);
        add_action('admin_menu', [$this, 'admin_menu_callback']);

        add_action( 'woocommerce_product_options_general_product_data', [$this, 'add_product_custom_fields'] );
        add_action( 'woocommerce_process_product_meta', [$this, 'save_product_custom_fields'] );

        add_filter('ledger_direct_init_form_fields', [$classAdmin, 'init_form_fields'], 10, 1);
        add_filter('ledger_direct_render_plugin_settings', [$classAdmin, 'render_plugin_settings'], 10, 1);
    }

    /**
     * Instanciate singleton
     *
     * @return void
     */
    public function plugins_loaded_callback() {
        if (class_exists('WooCommerce')) {
            // Initialize Ledger Direct Gateway
            LedgerDirectPaymentGateway::instance();
        }
    }

    /**
     * Add LedgerDirect settings link item to WooCommerce menu
     *
     * @return void
     */
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

    public function custom_price_html($price): string {
        global $product;

        $lpt_price = $product->get_meta('_ledger_direct_lpt_price');

        if (!empty($lpt_price)) {
            $price = $price . ' | <span class="woocommerce-Price-amount amount">' . $lpt_price . ' LPT</span>';
        }

        return $price;
    }

    /**
     * Add custom fields to the checkout page
     *
     * @return void
     */
    public function add_product_custom_fields(): void
    {
        echo '<div class="options_group">';
        woocommerce_wp_text_input(
            array(
                'id' => '_ledger_direct_lpt_price',
                'label' => __('LPT Price', 'woocommerce'),
                'desc_tip' => 'true',
                'description' => __('Enter the Loyalty Point price here.', 'woocommerce')
            )
        );
        echo '</div>';
    }

    /**
     * Save custom fields to the database
     *
     * @param $post_id
     * @return void
     */
    public function save_product_custom_fields($post_id): void
    {
        $custom_field_value = isset($_POST['_ledger_direct_lpt_price']) ? $_POST['_ledger_direct_lpt_price'] : '';
        update_post_meta($post_id, '_ledger_direct_lpt_price', sanitize_text_field($custom_field_value));
    }

    /**
     * Add custom URL scheme
     *
     * @return void
     */
    public function add_rewrite_rules(): void {
        add_rewrite_rule(
            'ledger-direct/payment/([a-z0-9-]+)[/]?$',
            'index.php?pagename=ledger-direct-payment&' . self::PAYMENT_IDENTIFIER . '=$matches[1]',
            'top'
        );
    }

    /**
     * Register GET variables for custom URL scheme
     *
     * @param $query_vars
     * @return array
     */
    public function add_query_vars($query_vars): array {
        $query_vars[] = self::PAYMENT_IDENTIFIER;

        return $query_vars;
    }

    /**
     * Register LedgerDirect as WooCommerce Payment Gateway
     *
     * @param $gateways
     * @return array
     */
    public function register_gateway($gateways): array {
        $gateways[] = 'Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway';
        $gateways[] = 'Hardcastle\LedgerDirect\Woocommerce\LedgerDirectXrpPaymentGateway';
        $gateways[] = 'Hardcastle\LedgerDirect\Woocommerce\LedgerDirectXrplTokenPaymentGateway';

        return $gateways;
    }

    /**
     * Links payment instructions to an order
     *
     * @param WC_Order $order
     * @param $data
     * @return void
     * @throws Exception
     */
    public function before_checkout_create_order(WC_Order $order, $data ): void {
        $payment_method = $order->get_payment_method();
        if ($payment_method !== 'ledger-direct-xrp' && $payment_method !== 'ledger-direct-xrpl-token') {
            return;
        }

        $container = ld_get_dependency_injection_container();
        $orderTransactionService = $container->get(OrderTransactionService::class);
        $orderTransactionService->prepareOrderForXrpl($order, $payment_method);
    }

    /**
     * Replace template with custom payment page
     *
     * @param $template
     * @return string
     */
    public function render_payment_page($template): string {
        $order_id = get_query_var(self::PAYMENT_IDENTIFIER);

        if (!empty($order_id)) {
            $this->enqueue_public_styles();
            $this->enqueue_public_scripts();
            return WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/views/ledger-direct_html.php';
        }

        return $template;
    }

    /**
     * Load translations
     *
     * @return void
     */
    public function load_translations(): void {
        load_plugin_textdomain(
            'ledger-direct',
            false,
            dirname(dirname(plugin_basename( __FILE__ ))) . '/languages/'
        );
    }

    /**
     * Add frontend styles
     *
     * @return void
     */
    public function enqueue_public_styles(): void {
        wp_enqueue_style(
            'ledger-direct',
            plugin_dir_url( __FILE__ ) . '../public/css/ledger-direct.css',
            []
        );
        wp_enqueue_style(
            'qr-bundle',
            plugin_dir_url( __FILE__ ) . '../public/css/qr-bundle.min.css',
            []
        );
    }

    /**
     * Add frontend scripts
     *
     * @return void
     */
    public function enqueue_public_scripts(): void {
        wp_enqueue_script(
            'bignumber',
            plugin_dir_url( __FILE__ ) . '../public/js/bignumber-9.1.2.min.js',
            []
        );
        wp_enqueue_script(
            'gemwallet',
            plugin_dir_url( __FILE__ ) . '../public/js/gemwallet-3.5.1.min.js',
            []
        );
        // wp_enqueue_script(
        //     'crossmark',
        //     plugin_dir_url( __FILE__ ) . '../public/js/crossmark-3.5.min.js',
        //     []
        // );
        wp_enqueue_script(
            'qr-bundle',
            plugin_dir_url( __FILE__ ) . '../public/js/qr-bundle.min.js',
            ['jquery']
        );
        wp_enqueue_script(
            'ledger-direct',
            plugin_dir_url( __FILE__ ) . '../public/js/ledger-direct.js',
            ['jquery', 'qr-bundle', 'bignumber', 'gemwallet']
        );
    }

}