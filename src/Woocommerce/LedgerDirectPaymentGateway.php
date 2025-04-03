<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Woocommerce;

use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use WC_Order;
use WC_Payment_Gateway;

class LedgerDirectPaymentGateway extends WC_Payment_Gateway
{
    public const LEDGER_DIRECT_PAYMENT_ID = 'ledger-direct';

    public const XRP_PAYMENT_ID = 'ledger-direct-xrp';

    public const XRP_PAYMENT_TYPE = 'xrp_payment';

    public const TOKEN_PAYMENT_ID = 'ledger-direct-xrpl-token';

    public const TOKEN_PAYMENT_TYPE = 'xrpl_token_payment';

    public static self|null $_instance = null;

    public string $xrpl_testnet_destination_account;

    public string $xrpl_mainnet_destination_account;


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
        if (empty($this->id)) {
            $this->id = self::LEDGER_DIRECT_PAYMENT_ID;
        }
        $this->icon = ld_get_public_url('/public/images/logo-40x40.png');
        $this->has_fields = false;
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
        $this->orderTransactionService = $container->get(OrderTransactionService::class);
    }

    /**
     * Initialise Gateway Settings Form Fields.
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
     * Prevent the default payment method from being selected
     *
     * @return bool
     */
    public function is_available(): bool
    {
        if ($this->id === self::LEDGER_DIRECT_PAYMENT_ID) {
            return false;
        }
        return true;
    }
}