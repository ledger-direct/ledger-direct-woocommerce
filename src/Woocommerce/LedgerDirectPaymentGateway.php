<?php

class LedgerDirectPaymentGateway extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'ledger-direct';
        $this->icon = WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'admin/public/images/label.svg';
        $this->has_fields = false;
        $this->method_title = __("Accept XRPL payments", "ledger-direct");
        $this->method_description = __("Receive XRP and any supported currencies into your XRPL account", "ledger-direct");
        $this->enabled = $this->get_option('enabled');
        $this->testnet_destination_account = $this->get_option('testnet__account');
        $this->mainnet_destination_account = $this->get_option('mainnet__account');

        $this->supports = ['products'];

        // TODO: Check if logged in!

        //$this->enabled = empty($this->logged_in) ? 'false' : $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->xrpl_network = $this->get_option('xrpl_network', 'testnet');




        //$this->init_form_fields();
        //$this->init_settings();

        //add_action( 'woocommerce_update_options_payment_gateways_ledger_direct', [ $this, 'process_admin_options' ]);
    }


}