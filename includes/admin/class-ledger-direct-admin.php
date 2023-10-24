<?php

class LedgerDirectAdmin
{
    public function init_form_fields($context): void {
        $context->form_fields = [
            'xrpl_network' => [
                'title'       => __("XRPL Network", 'ledger-direct'),
                'description' => __("Choose the XRPL Network", 'ledger-direct'),
                'type'        => 'select',
                'options'     => array(
                    'mainnet' => 'Mainnet',
                    'testnet' => 'Testnet',
                ),
                'default'     => 'mainnet',
                'desc_tip'    => true
            ],
            'xrpl_testnet_destination_account' => array(
                'title'       => __("Merchant Account - TESTNET", 'ledger-direct'),
                'type'        => 'text',
                'description' => __('Merchant Account description', 'ledger-direct'),
                'default'     => '',
                'desc_tip'    => true
            ),
            'xrpl_mainnet_destination_account' => array(
                'title'       => __("Merchant Account - MAINNET", 'ledger-direct'),
                'type'        => 'text',
                'description' => __('Merchant Account description', 'ledger-direct'),
                'default'     => '',
                'desc_tip'    => true
            )
        ];
    }

    public function render_plugin_settings($context): void {
        require_once WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/admin/views/settings_html.php';
    }
}