<?php

defined( 'ABSPATH' ) || exit(); // Exit if accessed directly

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway as LedgerDirectPaymentGateway;

final class LedgerDirectBlocks extends AbstractPaymentMethodType {

    private LedgerDirectPaymentGateway $gateway;

    protected $name = 'ledger-direct';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_ledger-direct_settings', []);
        $gateways       = WC()->payment_gateways->payment_gateways();
        $this->gateway  = $gateways[ $this->name ];
    }

    /**
     * Determines if the payment method is active and should be made available to customers.
     *
     * @return bool
     */
    public function is_active() {
        return $this->gateway->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $script_path       = '/assets/js/frontend/blocks.js';
        $script_asset_path = LedgerDirect::plugin_abspath() . 'assets/js/frontend/blocks.asset.php';
        $script_asset      = file_exists( $script_asset_path )
            ? require( $script_asset_path )
            : array(
                'dependencies' => array(),
                'version'      => '1.2.0'
            );
        $script_url        = LedgerDirect::plugin_url() . $script_path;

        wp_register_script(
            'ledger-direct-payments-blocks',
            $script_url,
            $script_asset[ 'dependencies' ],
            $script_asset[ 'version' ],
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'ledger-direct-blocks', 'woocommerce-gateway-ledger-direct', LedgerDirect::plugin_abspath() . 'languages/' );
        }

        return [ 'ledger-direct-payments-blocks' ];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        $configuration = ledger_direct_get_configuration();
        return [
            'title'       => $this->get_setting( 'title' ),
            'description' => $this->get_setting( 'description' ),
            'supports'    => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] ),
            'rlusd_available' => $configuration[ 'rlusd_available' ] ?? false,
            'usdc_available' => $configuration[ 'usdc_available' ] ?? false
        ];
    }

}