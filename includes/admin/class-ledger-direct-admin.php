<?php declare(strict_types=1);

defined( 'ABSPATH' ) || exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Service\ConfigurationService;

class LedgerDirectAdmin
{
    public function init_form_fields($context): void {
        $context->form_fields = [
            ConfigurationService::CONFIG_KEY_NETWORK => [
                'title'       => __("XRPL Network", 'ledger-direct'),
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
                'title'       => __("Merchant Account - TESTNET", 'ledger-direct'),
                'type'        => 'text',
                'description' => __('Merchant Account address (TESTNET) - receiving account', 'ledger-direct'),
                'default'     => '',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_TESTNET_IS_RLUSD_ENABLED => [
                'title'       => __("Enable RLUSD - TESTNET", 'ledger-direct'),
                'type'        => 'checkbox',
                'label'       => __('Enable RLUSD payments on TESTNET', 'ledger-direct'),
                'default'     => 'no',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_MAINNET_ACCOUNT => [
                'title'       => __("Merchant Account - MAINNET", 'ledger-direct'),
                'type'        => 'text',
                'description' => __('Merchant Account address (MAINNET) - receiving account', 'ledger-direct'),
                'default'     => '',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_MAINNET_IS_RLUSD_ENABLED => [
                'title'       => __("Enable RLUSD - MAINNET", 'ledger-direct'),
                'type'        => 'checkbox',
                'label'       => __('Enable RLUSD payments on MAINNET', 'ledger-direct'),
                'default'     => 'no',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_PAYMENT_PAGE_TITLE => [
                'title'       => __("LedgerDirect Payment Page Title", 'ledger-direct'),
                'type'        => 'text',
                'description' => __('Title for LedgerDirect Payment Page', 'ledger-direct'),
                'default'     => 'LedgerDirect PaymentPage',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_EXPIRY => [
                'title'       => __("XRP quote expiry", 'ledger-direct'),
                'type'        => 'text',
                'description' => __('Validity of the quote in minutes', 'ledger-direct'),
                'default'     => 0,
                'desc_tip'    => true
            ]
        ];
    }

    public function render_plugin_settings($context): void {
        require_once LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/admin/views/settings_html.php';
    }
}