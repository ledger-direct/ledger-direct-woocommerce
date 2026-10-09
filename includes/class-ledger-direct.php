<?php declare(strict_types=1);

defined( 'ABSPATH' ) || exit(); // Exit if accessed directly

use Hardcastle\LedgerDirect\Api\PaymentStatusEndpoint;
use Hardcastle\LedgerDirect\Service\ServiceFactory;
use Hardcastle\LedgerDirect\Port\WpConfigProvider;
use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Hardcastle\LedgerDirect\Presentation\PaymentPagePresenter;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;
use Hardcastle\LedgerDirect\Woocommerce\PaymentIncompleteStatus;

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
        PaymentIncompleteStatus::register();
        add_action( 'init', [$this, 'add_rewrite_endpoint'] );
        add_filter( 'woocommerce_payment_gateways', [$this, 'register_gateway'] );
        add_action( 'woocommerce_blocks_loaded', [$this, 'add_block_support_for_gateway'] );
        add_action( 'woocommerce_checkout_create_order', [$this, 'before_checkout_create_order'], 20, 2 );
        add_filter( 'template_include', [$this, 'render_payment_page'] );

        add_action( 'plugins_loaded', [$this, 'load_translations'] );
        // The payment page's assets are enqueued by render_payment_page() alone: no other page needs them.

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

        // The LedgerDirect panel on the order page, HPOS screen or the classic one.
        add_action('add_meta_boxes', [$classAdmin, 'add_order_panel'], 10, 2);
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

            // The refresh of an expired quote is an explicit POST from the
            // expired block, never a side effect of loading the page: the
            // customer may have sent the old amount already, and the page
            // has to be able to say "expired" before it changes the number.
            if ($this->is_refresh_request($order)) {
                try {
                    $service->refreshExpiredQuote($order, $intent);
                } catch (Exception $e) {
                    self::log('Could not refresh the quote for order ' . $order->get_id() . ': ' . $e->getMessage(), 'error');
                }

                wp_safe_redirect(LedgerDirectPaymentGateway::get_payment_page_url($order));
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
            }

            /*
             * The view under includes/ is not scoped by the release build, so it must not name a
             * core class: it gets an array of scalars from the presenter in src/ and nothing else.
             */
            global $ledger_direct_order, $ledger_direct_view;
            $ledger_direct_order = $order;
            $ledger_direct_view = PaymentPagePresenter::present(
                $intent,
                $service->paymentStatus($intent),
                $service->shortfall($intent),
                $this->page_platform_values($order, $gateway)
            );

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
     * What only the platform knows about the page: the order's numbers and URLs, the
     * merchant's settings for the page's look. Read here so the presenter stays free of
     * WordPress and testable on its own.
     *
     * @return array<string, mixed>
     */
    private function page_platform_values(WC_Order $order, LedgerDirectPaymentGateway $gateway): array {
        $configuration = new ConfigurationService();
        $store_name = (string) get_bloginfo('name');

        return [
            'order_number' => (string) $order->get_order_number(),
            'order_key' => (string) $order->get_order_key(),
            'total' => (string) $order->get_total(),
            'shop_currency' => (string) $order->get_currency(),
            'fiat_display' => wp_strip_all_tags(html_entity_decode(wc_price($order->get_total(), ['currency' => $order->get_currency()]), ENT_QUOTES, 'UTF-8')),
            'quote_minutes' => (int) $configuration->get(ConfigurationService::CONFIG_KEY_EXPIRY, WpConfigProvider::DEFAULT_QUOTE_EXPIRY_MINUTES),
            'poll_url' => PaymentStatusEndpoint::url($order),
            'page_url' => LedgerDirectPaymentGateway::get_payment_page_url($order),
            'redirect_url' => $gateway->get_return_url($order),
            'cart_url' => wc_get_cart_url(),
            'home_url' => home_url('/'),
            'store_name' => $store_name,
            'page_title' => $configuration->getPaymentPageTitle() ?: (string) get_bloginfo('name'),
            'accent' => $configuration->getPaymentPageAccentColor(),
            'logo' => $this->page_logo($configuration, $store_name),
            'xaman_key' => $configuration->getXamanApiKey(),
            'wc_project' => $configuration->getWalletConnectProjectId(),
            'wallets_src' => ledger_direct_get_public_url('/public/js/ledger-direct-payment-ui/wallets.js'),
            'refresh_nonce' => wp_create_nonce(self::refresh_nonce_action($order)),
        ];
    }

    /**
     * Which logo the page's header shows: the site's custom logo, a media library picture the
     * merchant chose, or a monogram. Always a local attachment, never a typed-in URL; when the
     * picture cannot be resolved, the monogram takes over.
     *
     * @return array{mode: string, url: string|null, monogram: string}
     */
    private function page_logo(ConfigurationService $configuration, string $store_name): array {
        $mode = $configuration->getPaymentPageLogoMode();
        $url = null;

        if ($mode === ConfigurationService::LOGO_MODE_SHOP) {
            $site_logo_id = (int) get_theme_mod('custom_logo');
            $url = $site_logo_id > 0 ? (wp_get_attachment_image_url($site_logo_id, 'medium') ?: null) : null;
        } elseif ($mode === ConfigurationService::LOGO_MODE_CUSTOM) {
            $attachment_id = $configuration->getPaymentPageLogoAttachmentId();
            $url = $attachment_id > 0 && wp_attachment_is_image($attachment_id)
                ? (wp_get_attachment_image_url($attachment_id, 'medium') ?: null)
                : null;
        }

        if ($url === null && $mode !== ConfigurationService::LOGO_MODE_NONE) {
            $mode = ConfigurationService::LOGO_MODE_NONE;
        }

        return ['mode' => $mode, 'url' => $url, 'monogram' => PaymentPagePresenter::monogram($store_name)];
    }

    /**
     * Whether this request is the "get an updated amount" form of the
     * expired block: a POST to the payment page with the nonce the page
     * rendered for this order.
     */
    private function is_refresh_request(WC_Order $order): bool {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }

        if (!isset($_POST['ledger_direct_refresh'])) {
            return false;
        }

        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';

        return wp_verify_nonce($nonce, self::refresh_nonce_action($order)) !== false;
    }

    public static function refresh_nonce_action(WC_Order $order): string {
        return 'ledger_direct_refresh_' . $order->get_order_key();
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
        wp_enqueue_style('ledger-direct-payment-ui', ledger_direct_get_public_url('/public/css/payment-page.css'), [], self::asset_version('public/css/payment-page.css'));
    }

    /**
     * Add frontend scripts
     *
     * @return void
     */
    public function enqueue_public_scripts(): void {
        /*
         * The page script of @ledger-direct/payment-ui (public/js/ledger-direct-payment-ui/VERSION
         * names the package tag), a classic script without jQuery. The wallet library
         * (wallets.js, 1.6 MB) is deliberately not enqueued: the page fetches it by a native
         * import() from data-ld-wallets-src only when a customer opens the wallet list.
         */
        wp_enqueue_script('ledger-direct-payment-ui', ledger_direct_get_public_url('/public/js/ledger-direct-payment-ui/payment-page.js'), [], self::asset_version('public/js/ledger-direct-payment-ui/payment-page.js'), true);
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