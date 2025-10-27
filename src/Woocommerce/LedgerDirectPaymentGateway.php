<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use DI\DependencyException;
use DI\NotFoundException;
use GuzzleHttp\Exception\GuzzleException;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use LedgerDirect;
use WC_Order;
use WC_Payment_Gateway;


class LedgerDirectPaymentGateway extends WC_Payment_Gateway
{
    public const ID = 'ledger-direct';

    public const XRP_PAYMENT_ID = 'xrp';

    public const TOKEN_PAYMENT_ID = 'token';

    public const RLUSD_PAYMENT_ID = 'rlusd';

    public const USDC_PAYMENT_ID = 'usdc';

    public static self|null $_instance = null;

    public string $account;

    public string $xrpl_network;

    protected OrderTransactionService $orderTransactionService;

    public static function instance(): self
    {
        if (self::$_instance == null) {
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

        $container = ledger_direct_get_dependency_injection_container();
        $this->orderTransactionService = $container->get(OrderTransactionService::class);
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
        $payment_type = isset($_POST['ledger_direct_payment_type']) ? sanitize_text_field(wp_unslash($_POST['ledger_direct_payment_type'])) : 'xrp';

        if (!in_array($payment_type, ['xrp', 'rlusd', 'usdc'])) {
            wc_add_notice(__('Please select a valid payment method.', 'ledger-direct'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Prepares the order for XRPL payment and returns the redirect URL.
     *
     * @param int $order_id
     * @return array
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        $payment_type = isset($_POST['ledger_direct_payment_type']) ? sanitize_text_field(wp_unslash($_POST['ledger_direct_payment_type'])) : 'xrp';

        $container = ledger_direct_get_dependency_injection_container();
        $orderTransactionService = $container->get(OrderTransactionService::class);
        $orderTransactionService->prepareOrderForXrpl($order, $payment_type);

        // Generate the payment URL, depending on whether permalinks are enabled or not
        global $wp_rewrite;
        if ($wp_rewrite->using_permalinks()) {
            $payment_url = home_url('/ledger-direct-payment/' . $order->get_order_key() . '/');
        } else {
            $payment_url = home_url('/?ledger-direct-payment=' . $order->get_order_key());
        }

        return [
            'result' => 'success',
            'redirect' => $payment_url
        ];
    }

    /**
     * Syncs the order transaction with XRPL and checks if the payment is valid.
     *
     * @param WC_Order $order
     * @return bool
     * @throws GuzzleException
     */
    public function sync_and_check_payment(WC_Order $order): bool
    {
        try {
            $this->orderTransactionService->syncOrderTransactionWithXrpl($order);
        } catch (\Exception $e) {

        }

        $meta = $order->get_meta(LedgerDirect::META_KEY);
        if ($this->orderTransactionService->checkPayment($order)) {
            if ($meta['type'] === self::XRP_PAYMENT_ID ) {
                return $this->is_xrp_payment_valid($meta);
            } elseif ($meta['type'] === self::RLUSD_PAYMENT_ID || $meta['type'] === self::USDC_PAYMENT_ID) {
                return $this->is_token_payment_valid($meta);
            }
        }

        return false;
    }

    /**
     * Checks if the XRP payment is valid based on the delivered amount and requested amount.
     *
     * @param array $meta
     * @return bool
     */
    private function is_xrp_payment_valid(array $meta): bool
    {
        // Payment is settled, let's check whether the paid amount is enough
        $requestedXrpAmount = (float) $meta['amount_requested'];
        $paidXrpAmount = (float) $meta['delivered_amount'];

        return $requestedXrpAmount >= $paidXrpAmount;
    }

    /**
     * Checks if the token (RLUSD/USDC) payment is valid based on the delivered amount and requested amount.
     *
     * @param array $meta
     * @return bool
     */
    private function is_token_payment_valid(array $meta): bool
    {
        if (!isset($meta['delivered_amount']) || !isset($meta['amount_requested'])) {
            return false;
        }
        $requestedAmount = $meta['amount_requested'];
        $deliveredAmount = $meta['delivered_amount'];

        if ($deliveredAmount === $requestedAmount) {
            return true;
        }

        return false;
    }

}