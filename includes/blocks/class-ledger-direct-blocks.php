<?php

defined( 'ABSPATH' ) || exit(); // Exit if accessed directly

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class LedgerDirectBlocks extends AbstractPaymentMethodType {

    private $gateway;

    protected $name = 'ledger-direct';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_ledger-direct_settings', []);
        $gateways       = WC()->payment_gateways->payment_gateways();
        $this->gateway  = $gateways[ $this->name ];
    }

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
            'wc-ledger-direct-payments-blocks',
            $script_url,
            $script_asset[ 'dependencies' ],
            $script_asset[ 'version' ],
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'wc-ledger-direct-blocks', 'woocommerce-gateway-ledger-direct', LedgerDirect::plugin_abspath() . 'languages/' );
        }

        return [ 'wc-ledger-direct-payments-blocks' ];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        return [
            'title'       => $this->get_setting( 'title' ),
            'description' => $this->get_setting( 'description' ),
            'supports'    => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] )
        ];
    }

}