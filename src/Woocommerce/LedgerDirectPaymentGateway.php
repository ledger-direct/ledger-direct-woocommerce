<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\ServiceFactory;
use WC_Order;
use WC_Payment_Gateway;


class LedgerDirectPaymentGateway extends WC_Payment_Gateway
{
    public const ID = 'ledger-direct';

    public const XRP_PAYMENT_ID = 'xrp';

    public const RLUSD_PAYMENT_ID = 'rlusd';

    public const USDC_PAYMENT_ID = 'usdc';

    public static self|null $_instance = null;

    public string $account;

    public string $xrpl_network;

    protected OrderTransactionService $orderTransactionService;

    public static function instance(): self
    {
        if (self::$_instance === null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function __construct()
    {
        $this->id = self::ID;
        $this->method_title = 'LedgerDirect';
        $this->method_description = 'Accept payments (XRP, RLUSD, USDC) on your XRPL wallet';
        $this->title = esc_html__('Pay directly on XRPL with LedgerDirect', 'ledger-direct');
        $this->description = 'Choose your preferred XRPL payment method';
        $this->icon = ledger_direct_get_public_url('/public/images/checkout.png');

        $config = ledger_direct_get_configuration();

        $this->has_fields = true;
        $this->enabled = $config['enabled'];

        $this->xrpl_network = $config['xrpl_network'];
        $this->account = $config['destination_account'];

        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        add_action( 'woocommerce_update_options_payment_gateways_ledger-direct', [$this, 'process_admin_options']);

        $this->orderTransactionService = ServiceFactory::getInstance()->getOrderTransactionService();
    }

    /**
     * Initialize Gateway Settings Form Fields.
     *
     * @return void
     */
    public function init_form_fields(): void
    {
        apply_filters('ledger_direct_init_form_fields', $this);
    }

    /**
     * Callback used in "process_admin_options", Called in WC_Settings_API
     *
     * @return void
     */
    public function admin_options() : void
    {
        apply_filters('ledger_direct_render_plugin_settings', $this);
    }

    /**
     * Display the payment fields on the checkout page. This method is called by
     * WooCommerce to render the payment options.
     *
     * @return void
     */
    public function payment_fields(): void
    {
        $config = ledger_direct_get_configuration();

        if (!$config['wallet_available']) {
            echo '<p>' . esc_html__('The XRPL wallet is not configured. Please contact the site administrator.', 'ledger-direct') . '</p>';
            return;
        }

        echo '<div id="ledger-direct-payment-methods">';
        echo '<h4>' . esc_html__('Choose payment method', 'ledger-direct') . '</h4>';

        echo '<label>';
        echo '<input type="radio" name="ledger_direct_payment_type" value="xrp" checked> ';
        echo esc_html__('XRP', 'ledger-direct');
        echo '</label><br>';

        if ($config['rlusd_available']) {
            echo '<label>';
            echo '<input type="radio" name="ledger_direct_payment_type" value="rlusd"> ';
            echo esc_html__('RLUSD Stablecoin (XRPL)', 'ledger-direct');
            echo '</label><br>';
        }

        if ($config['usdc_available']) {
            echo '<label>';
            echo '<input type="radio" name="ledger_direct_payment_type" value="usdc"> ';
            echo esc_html__('USDC Stablecoin (XRPL)', 'ledger-direct');
            echo '</label>';
        }

        echo '</div>';
    }

    /**
     * Validates the fields submitted by the user on the checkout page. This method is called by
     * WooCommerce to ensure that the selected payment method is valid.
     *
     * @return bool True if the fields are valid, false otherwise.
     */
    public function validate_fields(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before calling this gateway method.
        $payment_type = isset($_POST['ledger_direct_payment_type']) ? sanitize_text_field(wp_unslash($_POST['ledger_direct_payment_type'])) : 'xrp';

        if (!array_key_exists($payment_type, OrderTransactionService::BASE_ASSET_BY_PAYMENT_TYPE)) {
            wc_add_notice(__('Please select a valid payment method.', 'ledger-direct'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Quotes the order in the chosen asset and returns the payment page URL.
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before calling this gateway method.
        $payment_type = isset($_POST['ledger_direct_payment_type']) ? sanitize_text_field(wp_unslash($_POST['ledger_direct_payment_type'])) : 'xrp';

        try {
            $this->orderTransactionService->prepareOrderForXrpl($order, $payment_type);
        } catch (Exception $exception) {
            ServiceFactory::getInstance()->getLogger()->error('Could not quote an order for XRPL payment', [
                'order_id' => $order->get_id(),
                'payment_type' => $payment_type,
                'exception' => $exception->getMessage(),
            ]);
            wc_add_notice(__('This payment method is temporarily unavailable. Please try again or choose another payment method.', 'ledger-direct'), 'error');

            return ['result' => 'failure'];
        }

        return [
            'result' => 'success',
            'redirect' => self::get_payment_page_url($order)
        ];
    }

    /**
     * The payment page URL, depending on whether permalinks are enabled or not.
     */
    public static function get_payment_page_url(WC_Order $order): string
    {
        global $wp_rewrite;

        if ($wp_rewrite->using_permalinks()) {
            return home_url('/ledger-direct-payment/' . $order->get_order_key() . '/');
        }

        return home_url('/?ledger-direct-payment=' . $order->get_order_key());
    }

    /**
     * Syncs the order with the XRPL and returns the fulfilled intent when a
     * payment has arrived (settled or not - see is_settled()), null otherwise.
     * Throttled per receiving account like the status endpoint: a reload
     * within the interval answers from what is stored. A failure is logged,
     * not thrown: the page still renders.
     */
    public function sync_payment(WC_Order $order): ?PaymentIntent
    {
        try {
            return $this->orderTransactionService->syncOrderTransactionThrottled($order);
        } catch (Exception $exception) {
            ServiceFactory::getInstance()->getLogger()->warning('Failed to sync order transaction with XRPL', [
                'order_id' => $order->get_id(),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Whether a fulfilled intent settles the order - the core's decision,
     * identical across every LedgerDirect plugin.
     */
    public function is_settled(PaymentIntent $intent): bool
    {
        return $this->orderTransactionService->isSettled($intent);
    }

    /**
     * Syncs and checks in one go, for callers that only need the answer.
     */
    public function sync_and_check_payment(WC_Order $order): bool
    {
        $intent = $this->sync_payment($order);

        return $intent !== null && $this->is_settled($intent);
    }
}
