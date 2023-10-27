<?php

namespace Hardcastle\LedgerDirect\Woocommerce;

class LedgerDirectPaymentGateway extends \WC_Payment_Gateway
{
    public static self|null $_instance = null;

    public string $xrpl_testnet_destination_account;

    public string $xrpl_mainnet_destination_account;

    public string $xrpl_network;

    public static function instance(): self
    {
        if (self::$_instance == null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function __construct()
    {
        $this->id = 'ledger-direct';
        $this->icon = plugin_dir_url( __FILE__ ) . 'includes/admin/public/images/label.svg';
        $this->has_fields = false;
        $this->method_title = __("Accept XRPL payments", "ledger-direct");
        $this->method_description = __("Receive XRP and any supported currencies into your XRPL account", "ledger-direct");
        $this->enabled = $this->get_option('enabled');
        $this->xrpl_testnet_destination_account = $this->get_option('xrpl_testnet_destination_account');
        $this->xrpl_mainnet_destination_account = $this->get_option('xrpl_mainnet_destination_account');

        $this->supports = ['products'];

        //$this->enabled = empty($this->logged_in) ? 'false' : $this->get_option('enabled');
        $this->title = 'Ledger Direct';//$this->get_option('title');
        $this->description = 'Pay with XRP'; //$this->get_option('description');
        $this->xrpl_network = $this->get_option('xrpl_network', 'testnet');

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        add_action( 'woocommerce_update_options_payment_gateways_ledger-direct', [$this, 'process_admin_options']);
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
     * Process the payment and return the result.
     *
     * @param $order_id
     * @return array
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order( $order_id );


        // Main Logic

        // Fetch Controller URL
        // Redirect to controller

        // Remove cart.
        // WC()->cart->empty_cart();

        $test = 1;

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }
}