<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Woocommerce;

use DI\DependencyException;
use DI\NotFoundException;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;
use Hardcastle\LedgerDirect\Service\ConfigurationService;
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

    public string $xrpl_testnet_destination_account;

    public string $xrpl_mainnet_destination_account;


    public string $xrpl_network;

    protected ConfigurationService $configurationService;

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
        $this->method_title = 'Ledger Direct';
        $this->method_description = 'Accept payments via XRPL (XRP, Tokens, RLUSD)';
        $this->title = 'Pay with XRPL';
        $this->description = 'Choose your preferred XRPL payment method';
        $this->icon = ld_get_public_url('/public/images/logo-40x40.png');
        $this->has_fields = true; // Wichtig für payment_fields()
        $this->enabled = $this->get_option('enabled');

        $this->xrpl_network = $this->get_option('xrpl_network', 'testnet');
        $this->xrpl_testnet_destination_account = $this->get_option('xrpl_testnet_destination_account');
        $this->xrpl_mainnet_destination_account = $this->get_option('xrpl_mainnet_destination_account');

        $this->supports = ['products'];

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        add_action( 'woocommerce_update_options_payment_gateways_ledger-direct', [$this, 'process_admin_options']);

        $container = ld_get_dependency_injection_container();
        $this->configurationService = $container->get(ConfigurationService::class);
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
     * Zeigt die Zahlungsmethoden-Auswahl im Checkout
     */
    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }

        echo '<div id="ledger-direct-payment-methods">';
        echo '<h4>' . __('Choose Payment Method', 'ledger-direct') . '</h4>';

        echo '<label>';
        echo '<input type="radio" name="ledger_direct_payment_type" value="xrp" checked> ';
        echo __('XRP', 'ledger-direct');
        echo '</label><br>';

        echo '<label>';
        echo '<input type="radio" name="ledger_direct_payment_type" value="token"> ';
        echo __('XRPL Token', 'ledger-direct');
        echo '</label><br>';

        echo '<label>';
        echo '<input type="radio" name="ledger_direct_payment_type" value="rlusd"> ';
        echo __('RLUSD Stablecoin', 'ledger-direct');
        echo '</label>';

        echo '</div>';
    }

    /**
     * Validiert die ausgewählte Zahlungsmethode
     */
    public function validate_fields(): bool
    {
        $payment_type = $_POST['ledger_direct_payment_type'] ?? '';

        if (!in_array($payment_type, ['xrp', 'token', 'rlusd'])) {
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
        $payment_type = $_POST['ledger_direct_payment_type'] ?? 'xrp';

        $container = ld_get_dependency_injection_container();
        $orderTransactionService = $container->get(OrderTransactionService::class);
        $orderTransactionService->prepareOrderForXrpl($order, $payment_type);

        $payment_url = home_url('/ledger-direct-payment/' . $order->get_order_key());

        return [
            'result' => 'success',
            'redirect' => $payment_url
        ];
    }

    public function sync_and_check_payment(WC_Order $order): bool
    {
        $meta = $order->get_meta(LedgerDirect::META_KEY);
        $this->orderTransactionService->syncOrderTransactionWithXrpl($order);
        if ($this->orderTransactionService->checkPayment($order)) {
            if ($meta['type'] === self::XRP_PAYMENT_ID ) {
                return $this->is_xrp_payment_valid($meta);
            } elseif ($meta['type'] === self::TOKEN_PAYMENT_ID) {
                return $this->is_token_payment_valid($meta);
            } elseif ($meta['type'] === self::RLUSD_PAYMENT_ID) {
                return $this->is_rlusd_payment_valid($meta);
            }
        }

        return false;
    }

    private function is_xrp_payment_valid(array $meta): bool
    {
        // Payment is settled, let's check whether the paid amount is enough
        $requestedXrpAmount = (float) $meta['amount_requested'];
        $paidXrpAmount = (float) $meta['delivered_amount'];
        $slippage = 0.0015; // TODO: Make this configurable
        $slipped = 1.0 - $paidXrpAmount / $requestedXrpAmount;
        return $slipped < $slippage;
    }

    private function is_token_payment_valid(array $meta): bool
    {
        return false;
    }

    private function is_rlusd_payment_valid(array $meta): bool
    {
        if (!isset($meta['delivered_amount']) || !isset($meta['amount_requested'])) {
            return false;
        }
        $requestedRlusdAmount = (float) $meta['amount_requested'];
        $paidRlusdAmount = (float) $meta['delivered_amount'];

        if ($requestedRlusdAmount <= 0 || $paidRlusdAmount <= 0) {
            return false;
        }

        if ($paidRlusdAmount === $requestedRlusdAmount) {
            return true;
        }

        return false;
    }
}