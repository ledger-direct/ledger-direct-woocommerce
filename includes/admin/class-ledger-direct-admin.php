<?php

defined( 'ABSPATH' ) || exit;

use Hardcastle\LedgerDirect\Service\ConfigurationService;

class LedgerDirectAdmin
{
    public function init_form_fields($context): void {
        $context->form_fields = [
            'xrpl_network' => [
                'title'       => __("XRPL Network", ConfigurationService::CONFIG_DOMAIN),
                'description' => __("Choose the XRPL Network", 'ledger-direct'),
                'type'        => 'select',
                'options'     => [
                    'mainnet' => 'Mainnet',
                    'testnet' => 'Testnet',
                ],
                'default'     => 'mainnet',
                'desc_tip'    => true
            ],
            'xrpl_testnet_destination_account' => [
                'title'       => __("Merchant Account - TESTNET", ConfigurationService::CONFIG_DOMAIN),
                'type'        => 'text',
                'description' => __('Merchant Account address (TESTNET) - receiving account', ConfigurationService::CONFIG_DOMAIN),
                'default'     => '',
                'desc_tip'    => true
            ],
            'xrpl_testnet_rlusd_enabled' => [
                'title'       => __("Enable RLUSD - TESTNET", ConfigurationService::CONFIG_DOMAIN),
                'type'        => 'checkbox',
                'label'       => __('Enable RLUSD payments on TESTNET', ConfigurationService::CONFIG_DOMAIN),
                'default'     => 'no',
                'desc_tip'    => true
            ],
            'xrpl_mainnet_destination_account' => [
                'title'       => __("Merchant Account - MAINNET", ConfigurationService::CONFIG_DOMAIN),
                'type'        => 'text',
                'description' => __('Merchant Account address (MAINNET) - receiving account', ConfigurationService::CONFIG_DOMAIN),
                'default'     => '',
                'desc_tip'    => true
            ],
            'xrpl_mainnet_rlusd_enabled' => [
                'title'       => __("Enable RLUSD - MAINNET", ConfigurationService::CONFIG_DOMAIN),
                'type'        => 'checkbox',
                'label'       => __('Enable RLUSD payments on MAINNET', ConfigurationService::CONFIG_DOMAIN),
                'default'     => 'no',
                'desc_tip'    => true
            ],
            'xrpl_payment_page_title' => [
                'title'       => __("LedgerDirect Payment Page Title", ConfigurationService::CONFIG_DOMAIN),
                'type'        => 'text',
                'description' => __('Title for LedgerDirect Payment Page', ConfigurationService::CONFIG_DOMAIN),
                'default'     => 'LedgerDirect PaymentPage',
                'desc_tip'    => true
            ],
            'xrpl_quote_expiry' => [
                'title'       => __("XRP quote expiry", ConfigurationService::CONFIG_DOMAIN),
                'type'        => 'text',
                'description' => __('Validity of the quote in minutes', ConfigurationService::CONFIG_DOMAIN),
                'default'     => 0,
                'desc_tip'    => true
            ]
        ];
    }

    public function render_plugin_settings($context): void {
        require_once WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/admin/views/settings_html.php';
    }
}