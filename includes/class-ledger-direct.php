<?php declare(strict_types=1);

defined( 'ABSPATH' ) || exit(); // Exit if accessed directly

use Hardcastle\LedgerDirect\Service\ServiceFactory;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;

class LedgerDirect
{
    public const META_KEY = '_ledger_direct';
    public const ORDER_IDENTIFIER = 'ledger-direct-payment';

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
        require_once LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/admin/class-ledger-direct-admin.php';

        if (class_exists('WooCommerce')) {
            LedgerDirectPaymentGateway::instance();
        }
    }

    /**
     * Register custom query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'ledger-direct-payment';
        return $vars;
    }

    /**
     * Register public actions and filters
     *
     * @return void
     */
    public function public_hooks(): void {
        add_action( 'init', [$this, 'add_rewrite_endpoint'] );
        add_filter( 'woocommerce_payment_gateways', [$this, 'register_gateway'] );
        add_action( 'woocommerce_blocks_loaded', [$this, 'add_block_support_for_gateway'] );
        add_action( 'woocommerce_checkout_create_order', [$this, 'before_checkout_create_order'], 20, 2 );
        add_filter( 'template_include', [$this, 'render_payment_page'] );

        add_action( 'plugins_loaded', [$this, 'load_translations'] );
        add_action( 'wp_enqueue_scripts', [$this, 'enqueue_public_styles'] );
        add_action( 'wp_enqueue_scripts', [$this, 'enqueue_public_scripts'] );

        add_filter('query_vars', [$this, 'add_query_vars']);
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
            __('LedgerDirect', 'ledger-direct'),
            __('LedgerDirect', 'ledger-direct'),
            'manage_woocommerce',
             admin_url('admin.php?page=wc-settings&tab=checkout&section=ledger-direct'),
            null
        );
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
     * Add support for LedgerDirect in WooCommerce Blocks
     *
     * @return void
     */
    public function add_block_support_for_gateway(): void {
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            require_once LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/blocks/class-ledger-direct-blocks.php';
            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                    $payment_method_registry->register(new LedgerDirectBlocks());
                }
            );
        }
    }

    /**
     * Plugin url.
     *
     * @return string
     */
    public static function plugin_url() {
        return untrailingslashit( plugins_url( '/', __FILE__ ) );
    }

    /**
     * Plugin url.
     *
     * @return string
     */
    public static function plugin_abspath() {
        return trailingslashit(plugin_dir_path(__FILE__));
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
        $order_key = get_query_var(self::ORDER_IDENTIFIER);

        if (!empty($order_key)) {
            $order = $this->get_order_by_order_key($order_key);

            if (!$order) {
                // Order not found - show 404
                self::log('Order not found for key: ' . $order_key, 'debug');

                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                return get_404_template();
            }

            $gateway = LedgerDirectPaymentGateway::instance();
            if (!$gateway->is_available()) {
                wc_add_notice(__('Payment gateway is not available.', 'ledger-direct'), 'error');
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }

            if ($order->is_paid() || !$order->needs_payment()) {
                wp_safe_redirect($gateway->get_return_url($order));
                exit;
            }

            $service = ServiceFactory::getInstance()->getOrderTransactionService();

            try {
                $intent = $service->readPaymentIntent($order);
            } catch (Exception $e) {
                self::log('Unreadable payment record on order ' . $order->get_id() . ': ' . $e->getMessage(), 'error');
                $intent = null;
            }

            if ($intent === null) {
                wc_add_notice(__('An error occurred while processing your payment. Please contact support.', 'ledger-direct'), 'error');
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }

            $fulfilled = $gateway->sync_payment($order);

            if ($fulfilled !== null) {
                $intent = $fulfilled;

                if ($gateway->is_settled($fulfilled)) {
                    $order->payment_complete((string) $fulfilled->hash);
                    if (WC()->cart) {
                        WC()->cart->empty_cart();
                    }
                    wp_safe_redirect($gateway->get_return_url($order));
                    exit;
                }
            } else {
                // Nothing arrived yet: an expired quote is refreshed in place,
                // keeping the destination account and tag the customer may
                // already have in their wallet.
                try {
                    $intent = $service->refreshExpiredQuote($order, $intent);
                } catch (Exception $e) {
                    self::log('Could not refresh the quote for order ' . $order->get_id() . ': ' . $e->getMessage(), 'error');
                }
            }

            global $ledger_direct_order, $ledger_direct_intent, $ledger_direct_shortfall;
            $ledger_direct_order = $order;
            $ledger_direct_intent = $intent;
            $ledger_direct_shortfall = $service->shortfall($intent);

            $this->enqueue_public_styles();
            $this->enqueue_public_scripts();

            $template_path = LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/views/ledger-direct_html.php';

            if (!file_exists($template_path)) {
                self::log('Template file not found: ' . $template_path, 'error');
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
        $order = wc_get_order(wc_get_order_id_by_order_key($order_key));

        if ($order && $order->get_order_key() === $order_key) {
            return $order;
        }

        return false;
    }


    /**
     * Load translations - obsolete, kept for compatibility
     *
     * @return void
     */
    public function load_translations(): void {
        // load_plugin_textdomain(
        //    'ledger-direct',
        //    false,
        //    dirname(dirname(plugin_basename( __FILE__ ))) . '/languages/'
        // );
    }

    /**
     * Add frontend styles
     *
     * @return void
     */
    public function enqueue_public_styles(): void {
        wp_enqueue_style('ledger-direct', ledger_direct_get_public_url('/public/css/ledger-direct.css'), [], self::asset_version('public/css/ledger-direct.css'));
        wp_enqueue_style('qr-bundle', ledger_direct_get_public_url('/public/css/qr-bundle.min.css'), [], self::asset_version('public/css/qr-bundle.min.css'));
    }

    /**
     * Add frontend scripts
     *
     * @return void
     */
    public function enqueue_public_scripts(): void {
        wp_enqueue_script('jquery-qrcode', ledger_direct_get_public_url('/public/js/jquery-qrcode.min.js'), ['jquery'], self::asset_version('public/js/jquery-qrcode.min.js'), true);
        wp_enqueue_script('ledger-direct', ledger_direct_get_public_url('/public/js/ledger-direct.js'), ['jquery', 'jquery-qrcode'], self::asset_version('public/js/ledger-direct.js'), true);
    }

    /**
     * File modification time as the asset version, so browsers pick up a
     * changed script or stylesheet after an update instead of a cached copy.
     *
     * @param string $relative_path Path relative to the plugin root.
     * @return string
     */
    private static function asset_version(string $relative_path): string {
        $file = LEDGER_DIRECT_PLUGIN_FILE_PATH . $relative_path;

        return file_exists($file) ? (string) filemtime($file) : '1.0.0';
    }

}