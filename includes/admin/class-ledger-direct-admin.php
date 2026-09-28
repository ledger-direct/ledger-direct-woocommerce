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
                'default'     => 'testnet',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_MAINNET_ACCOUNT => [
                'title'       => __("Merchant Account - MAINNET", 'ledger-direct'),
                'type'        => 'text',
                'description' => __('Merchant Account address (MAINNET) - receiving account', 'ledger-direct'),
                'default'     => '',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_TESTNET_ACCOUNT => [
                'title'       => __("Merchant Account - TESTNET", 'ledger-direct'),
                'type'        => 'text',
                'description' => __('Merchant Account address (TESTNET) - receiving account', 'ledger-direct'),
                'default'     => '',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_IS_RLUSD_ENABLED => [
                'title'       => __("Enable RLUSD payments title", 'ledger-direct'),
                'type'        => 'checkbox',
                'label'       => __('Enable RLUSD payments', 'ledger-direct'),
                'default'     => 'no',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_IS_USDC_ENABLED => [
                'title'       => __("Enable USDC payments title", 'ledger-direct'),
                'type'        => 'checkbox',
                'label'       => __('Enable USDC payments', 'ledger-direct'),
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
                'default'     => 15,
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_PAGE_LOGO_MODE => [
                'title'       => __('Payment page: logo', 'ledger-direct'),
                'type'        => 'select',
                'options'     => [
                    ConfigurationService::LOGO_MODE_SHOP => __('The site logo', 'ledger-direct'),
                    ConfigurationService::LOGO_MODE_CUSTOM => __('A picture from the media library', 'ledger-direct'),
                    ConfigurationService::LOGO_MODE_NONE => __('No logo, the first letter of the site name', 'ledger-direct'),
                ],
                'default'     => ConfigurationService::LOGO_MODE_SHOP,
                'description' => __('Shown in the header of the payment page, at most 160 by 32 pixels.', 'ledger-direct'),
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_PAGE_LOGO => [
                'title'       => __('Payment page: picture', 'ledger-direct'),
                'type'        => 'text',
                'description' => __('The attachment ID or the URL of a picture in this site\'s media library. Only used with "A picture from the media library".', 'ledger-direct'),
                'default'     => '',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_PAGE_ACCENT => [
                'title'       => __('Payment page: accent colour', 'ledger-direct'),
                'type'        => 'color',
                'description' => __('Buttons, the countdown bar and the destination tag are drawn in this colour with white text on it, so it has to be dark enough (contrast 4.5:1). Default #1f5eff.', 'ledger-direct'),
                'default'     => '#1f5eff',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_XAMAN_API_KEY => [
                'title'       => __('Payment page: Xaman API key', 'ledger-direct'),
                'type'        => 'text',
                'description' => __('The public API key of your Xaman developer app (apps.xaman.dev). With it, customers on a phone get an "Open in wallet app" button. Never the API secret.', 'ledger-direct'),
                'default'     => '',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_WALLETCONNECT_PROJECT_ID => [
                'title'       => __('Payment page: WalletConnect project id', 'ledger-direct'),
                'type'        => 'text',
                'description' => __('The project id from cloud.walletconnect.com, if you want to offer WalletConnect wallets. Public, like the Xaman key.', 'ledger-direct'),
                'default'     => '',
                'desc_tip'    => true
            ]
        ];
    }

    public function render_plugin_settings($context): void {
        require_once LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/admin/views/settings_html.php';
    }
}