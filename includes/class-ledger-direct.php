<?php

defined( 'ABSPATH' ) || exit();

use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;

class LedgerDirect
{
    public const META_KEY = '_ledger_direct';
    public const PAYMENT_IDENTIFIER = 'ledger-direct-payment';

    public static self|null $_instance = null;

    /**
     * Get the singleton instance of LedgerDirect
     *
     * @return self
     */
    public static function instance(): self
    {
        if (self::$_instance == null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * LedgerDirect constructor.
     */
    public function __construct() {
        $this->load_dependencies();

        $this->public_hooks();
        if ( is_admin() ) {
            $this->admin_hooks();
        }
    }

    /**
     * Log messages to WooCommerce logger
     *
     * @param $message
     * @param $level
     * @return void
     */
    public static function log($message, $level = 'info'): void {
        if (!class_exists('WC_Logger')) {
            return;
        }

        $logger = wc_get_logger();
        $context = array('source' => 'ledger-direct');

        switch ($level) {
            case 'error':
                $logger->error($message, $context);
                break;
            case 'warning':
                $logger->warning($message, $context);
                break;
            case 'debug':
                $logger->debug($message, $context);
                break;
            default:
                $logger->info($message, $context);
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
        add_action('init', [$this, 'add_rewrite_endpoint']);
        add_filter('woocommerce_payment_gateways', [$this, 'register_gateway']);
        add_filter('woocommerce_get_price_html', [$this, 'custom_price_html']);
        add_filter('woocommerce_checkout_create_order', [$this, 'before_checkout_create_order'], 20, 2);
        add_filter('template_include', [$this, 'render_payment_page']);

        add_action( 'plugins_loaded', [$this, 'load_translations'] );
        add_action( 'wp_enqueue_scripts', [$this, 'enqueue_public_styles'] );
        add_action( 'wp_enqueue_scripts', [$this, 'enqueue_public_scripts'] );

        add_action('wp_ajax_ledger_direct_change_payment_method', [$this, 'ajax_change_payment_method']);
        add_action('wp_ajax_nopriv_ledger_direct_change_payment_method', [$this, 'ajax_change_payment_method']);
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
     * Instantiate singleton
     *
     * @return void
     */
    public function plugins_loaded_callback(): void
    {
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

    /**
     * AJAX-Handler für Zahlungsmethoden-Wechsel
     */
    public function ajax_change_payment_method(): void {
        // Nonce-Prüfung
        if (!wp_verify_nonce($_POST['nonce'], 'ledger_direct_nonce')) {
            wp_die('Security check failed');
        }

        $order_id = sanitize_text_field($_POST['order_id']);
        $payment_type = sanitize_text_field($_POST['payment_type']);

        if (!in_array($payment_type, ['xrp', 'token', 'rlusd'])) {
            wp_send_json_error('Invalid payment type');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Order not found');
        }

        // Zahlungstyp aktualisieren
        $order->update_meta_data('_ledger_direct_payment_type', $payment_type);
        $order->save();

        // Neue Zahlungsdaten für XRPL vorbereiten
        $container = ld_get_dependency_injection_container();
        $orderTransactionService = $container->get(OrderTransactionService::class);
        $payment_data = $orderTransactionService->prepareOrderForXrpl($order, $payment_type);

        wp_send_json_success([
            'payment_type' => $payment_type,
            'payment_data' => $payment_data,
            'message' => __('Payment method updated successfully', 'ledger-direct')
        ]);
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
     * Add custom URL endpoint for Ledger Direct payment page
     *
     * @return void
     */
    public function add_rewrite_endpoint(): void
    {
        add_rewrite_endpoint('ledger-direct-payment', EP_ROOT);
        // flush_rewrite_rules();
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
     * Links payment instructions to an order
     *
     * @param WC_Order $order
     * @param $data
     * @return void
     * @throws Exception
     */
    public function before_checkout_create_order(WC_Order $order, $data ): void {
        // Is already handled by LedgerDirectPaymentGateway
    }

    /**
     * Replace template with custom payment page
     *
     * @param $template
     * @return string
     */
    public function render_payment_page($template): string {
        $order_key = get_query_var(self::PAYMENT_IDENTIFIER);

        if (!empty($order_key)) {
            $order = $this->get_order_by_order_key($order_key);

            if (!$order) {
                // Order nicht gefunden - 404 anzeigen
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Ledger Direct: Order not found for key: " . $order_key);
                }

                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                return get_404_template();
            }

            $gateway = LedgerDirectPaymentGateway::instance();
            $is_paid = $gateway->sync_and_check_payment($order);
            if ($is_paid) {
                wp_redirect($gateway->get_return_url($order));
                //wp_redirect(wc_get_checkout_url());
                exit;
            }

            global $ledger_direct_order;
            $ledger_direct_order = $order;

            $this->enqueue_public_styles();
            $this->enqueue_public_scripts();

            $template_path = WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/views/ledger-direct_html.php';

            if (!file_exists($template_path)) {
                error_log("Ledger Direct: Template file not found: " . $template_path);
                return $template;
            }

            return $template_path;
        }

        return $template;
    }

    /**
     * Get Order by Order Key
     *
     * @param string $order_key
     * @return WC_Order|false
     */
    private function get_order_by_order_key(string $order_key) {
        $order = wc_get_order($order_key);

        if ($order && $order->get_order_key() === $order_key) {
            return $order;
        }

        // Fallback: Manual DB search
        global $wpdb;

        // // Fallback: Manual DB search for HPOS
        if (class_exists('Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore')) {
            $order_id = $wpdb->get_var($wpdb->prepare(
                "SELECT order_id FROM {$wpdb->prefix}wc_order_operational_data WHERE order_key = %s",
                $order_key
            ));

            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order) {
                    return $order;
                }
            }
        }

        // Fallback: Legacy Post Meta
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
         WHERE meta_key = '_order_key' AND meta_value = %s",
            $order_key
        ));

        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                return $order;
            }
        }

        return false;
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
        //    []
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