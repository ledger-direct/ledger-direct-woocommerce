<?php

defined( 'ABSPATH' ) || exit;

use Hardcastle\LedgerDirect\Service\ConfigurationService;

class LedgerDirectAdmin
{
    public function init_form_fields($context): void {
        $context->form_fields = [
            ConfigurationService::CONFIG_KEY_NETWORK => [
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
            ConfigurationService::CONFIG_KEY_TESTNET_ACCOUNT => [
                'title'       => __("Merchant Account - TESTNET", ConfigurationService::CONFIG_DOMAIN),
                'type'        => 'text',
                'description' => __('Merchant Account address (TESTNET) - receiving account', ConfigurationService::CONFIG_DOMAIN),
                'default'     => '',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_TESTNET_TOKEN_NAME => [
                'title'       => __("Token Name - TESTNET", ConfigurationService::CONFIG_DOMAIN),
                'type'        => 'text',
                'description' => __('', ConfigurationService::CONFIG_DOMAIN),
                'default'     => '',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_TESTNET_TOKEN_ISSUER => [
                'title'       => __("Token Issuer - TESTNET", ConfigurationService::CONFIG_DOMAIN),
                'type'        => 'text',
                'description' => __('', ConfigurationService::CONFIG_DOMAIN),
                'default'     => '',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_MAINNET_ACCOUNT => [
                'title'       => __("Merchant Account - MAINNET", ConfigurationService::CONFIG_DOMAIN),
                'type'        => 'text',
                'description' => __('Merchant Account address (MAINNET) - receiving account', ConfigurationService::CONFIG_DOMAIN),
                'default'     => '',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_MAINNET_TOKEN_NAME => [
                'title'       => __("Token Name - MAINNET", ConfigurationService::CONFIG_DOMAIN),
                'type'        => 'text',
                'description' => __('', ConfigurationService::CONFIG_DOMAIN),
                'default'     => '',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_MAINNET_TOKEN_ISSUER => [
                'title'       => __("Token Issuer - MAINNET", ConfigurationService::CONFIG_DOMAIN),
                'type'        => 'text',
                'description' => __('', ConfigurationService::CONFIG_DOMAIN),
                'default'     => '',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_PAYMENT_PAGE_TITLE => [
                'title'       => __("LedgerDirect Payment Page Title", ConfigurationService::CONFIG_DOMAIN),
                'type'        => 'text',
                'description' => __('Title for LedgerDirect Payment Page', ConfigurationService::CONFIG_DOMAIN),
                'default'     => 'LedgerDirect PaymentPage',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_EXPIRY => [
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