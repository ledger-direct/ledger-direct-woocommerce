<?php declare(strict_types=1);

defined( 'ABSPATH' ) || exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Core\Account\Finding;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Port\WpConfigProvider;
use Hardcastle\LedgerDirect\Presentation\OrderPanelPresenter;
use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Hardcastle\LedgerDirect\Service\ServiceFactory;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;

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
                'title'       => __('RLUSD payments', 'ledger-direct'),
                'type'        => 'checkbox',
                'label'       => __('Enable RLUSD payments', 'ledger-direct'),
                'default'     => 'no',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_IS_USDC_ENABLED => [
                'title'       => __('USDC payments', 'ledger-direct'),
                'type'        => 'checkbox',
                'label'       => __('Enable USDC payments', 'ledger-direct'),
                'default'     => 'no',
                'desc_tip'    => true
            ],
            // Xahau: a network of its own that speaks the XRP Ledger's protocol. Its
            // keys follow the pattern every chain after the XRP Ledger takes.
            WpConfigProvider::KEY_XAHAU_ENABLED => [
                'title'       => __('Xahau', 'ledger-direct'),
                'type'        => 'checkbox',
                'label'       => __('Accept XAH on Xahau', 'ledger-direct'),
                'description' => __('Xahau is a network of its own that speaks the XRP Ledger\'s protocol: payments in XAH, by destination tag, to an account of the same form. Use an account that receives on Xahau alone, not one that also receives on the XRP Ledger.', 'ledger-direct'),
                'default'     => 'no',
                'desc_tip'    => true
            ],
            WpConfigProvider::KEY_XAHAU_NETWORK => [
                'title'       => __('Xahau Network', 'ledger-direct'),
                'description' => __('Choose the Xahau network', 'ledger-direct'),
                'type'        => 'select',
                'options'     => [
                    'mainnet' => 'Mainnet',
                    'testnet' => 'Testnet',
                ],
                'default'     => 'testnet',
                'desc_tip'    => true
            ],
            WpConfigProvider::KEY_XAHAU_MAINNET_ACCOUNT => [
                'title'       => __('Xahau Account - MAINNET', 'ledger-direct'),
                'type'        => 'text',
                'description' => __('Receiving account on the Xahau mainnet', 'ledger-direct'),
                'default'     => '',
                'desc_tip'    => true
            ],
            WpConfigProvider::KEY_XAHAU_TESTNET_ACCOUNT => [
                'title'       => __('Xahau Account - TESTNET', 'ledger-direct'),
                'type'        => 'text',
                'description' => __('Receiving account on the Xahau testnet', 'ledger-direct'),
                'default'     => '',
                'desc_tip'    => true
            ],
            ConfigurationService::CONFIG_KEY_PAYMENT_PAGE_TITLE => [
                'title'       => __("LedgerDirect Payment Page Title", 'ledger-direct'),
                'type'        => 'text',
                'description' => __('Title for LedgerDirect Payment Page', 'ledger-direct'),
                'default'     => __('LedgerDirect PaymentPage', 'ledger-direct'),
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

    /**
     * What stands in a customer's way, per chain with a saved receiving account (core 0.12,
     * INVARIANTS.md "Receiving account"): a token without a trustline, an account that is not
     * activated, deposit authorization. Shown above the settings, which stay as they are — the
     * checkout leaves out what the account cannot take. Read afresh from the ledger.
     *
     * @return list<array{class: string, text: string}>
     */
    public static function account_check_notices(): array {
        $services = ServiceFactory::getInstance();
        $config = $services->getConfigProvider();
        $notices = [];

        foreach ([WpConfigProvider::CHAIN, WpConfigProvider::CHAIN_XAHAU] as $chain) {
            if (!$config->hasDestinationAccount($chain)) {
                continue;
            }
            $descriptor = ChainCatalog::chain($chain);
            $assets = [];
            foreach ($descriptor->assets as $asset) {
                if ($config->isAssetEnabled($chain, $asset->code)) {
                    $assets[] = $asset->code;
                }
            }
            if ($assets === []) {
                continue;
            }

            $account = $config->getDestinationAccount($chain);
            $network = $config->getNetwork($chain);
            $assessment = $services->getAccountInspector($chain)->assess($account, $network, $assets);

            if ($assessment->isClean()) {
                /* translators: 1: chain name, e.g. XRP Ledger, 2: account address, 3: network (mainnet or testnet) */
                $notices[] = ['class' => 'notice-success', 'text' => sprintf(__('%1$s: the receiving account %2$s on the %3$s can take every asset that is switched on.', 'ledger-direct'), $descriptor->label, $account, $network)];
                continue;
            }

            foreach ($assessment->findings as $finding) {
                $blocking = $finding->blocksOffer || $finding->code === Finding::ACCOUNT_MISSING;
                $notices[] = ['class' => $blocking ? 'notice-warning' : 'notice-info', 'text' => $descriptor->label . ': ' . self::finding_text($finding, $descriptor->nativeAsset)];
            }
        }

        return $notices;
    }

    private static function finding_text(Finding $finding, string $native): string {
        $asset = (string) $finding->asset;
        $issuer = (string) ($finding->context['issuer'] ?? '');

        switch ($finding->code) {
            case Finding::ACCOUNT_MISSING:
                /* translators: 1: base reserve, e.g. 1, 2: native asset, e.g. XRP */
                return sprintf(__('The account is not activated yet. A first payment of at least %1$s %2$s activates it; until then smaller orders in %2$s fail, and tokens are not offered.', 'ledger-direct'), (string) ($finding->context['reserve'] ?? ''), $native);
            case Finding::DEPOSIT_AUTH:
                return __('Deposit authorization is set on the account: only senders you authorized can pay. The payment methods stay in the checkout.', 'ledger-direct');
            case Finding::TRUSTLINE_MISSING:
                /* translators: 1: asset, e.g. RLUSD, 2: issuer address */
                return sprintf(__('%1$s is not offered in the checkout: the account has no trustline to the issuer %2$s.', 'ledger-direct'), $asset, $issuer);
            case Finding::TRUSTLINE_NOT_AUTHORIZED:
                /* translators: 1: asset, e.g. RLUSD, 2: issuer address */
                return sprintf(__('%1$s is not offered in the checkout: the issuer %2$s has not authorized the trustline.', 'ledger-direct'), $asset, $issuer);
            case Finding::TRUSTLINE_FROZEN:
                /* translators: %s: asset, e.g. RLUSD */
                return sprintf(__('%s is not offered in the checkout: the trustline is frozen.', 'ledger-direct'), $asset);
            case Finding::TRUSTLINE_FULL:
                /* translators: %s: asset, e.g. RLUSD */
                return sprintf(__('%s is not offered in the checkout: the trustline has reached its limit.', 'ledger-direct'), $asset);
            case Finding::ISSUER_FROZEN:
                /* translators: 1: asset, e.g. RLUSD, 2: issuer address */
                return sprintf(__('%1$s is not offered in the checkout: the issuer %2$s has frozen all its trustlines.', 'ledger-direct'), $asset, $issuer);
            case Finding::NOT_CHECKED:
                /* translators: %s: native asset, e.g. XRP */
                return sprintf(__('The ledger could not be asked just now. Until it can, tokens are not offered; %s is.', 'ledger-direct'), $native);
            default:
                return $finding->code;
        }
    }

    /**
     * The LedgerDirect panel on the order page: what was quoted, what arrived,
     * what is still due, every transaction on the tag. Only on orders paid
     * through this gateway. Registered for both order screens, because the
     * screen id depends on whether HPOS is on.
     *
     * @param string $screen
     * @param mixed $post_or_order
     */
    public function add_order_panel(string $screen, $post_or_order = null): void {
        $order_screens = ['shop_order'];
        if (function_exists('wc_get_page_screen_id')) {
            $order_screens[] = wc_get_page_screen_id('shop-order');
        }

        if (!in_array($screen, $order_screens, true)) {
            return;
        }

        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order instanceof WP_Post ? $post_or_order->ID : $post_or_order);

        if (!$order instanceof WC_Order || $order->get_payment_method() !== LedgerDirectPaymentGateway::ID) {
            return;
        }

        add_meta_box(
            'ledger-direct-order-panel',
            __('LedgerDirect', 'ledger-direct'),
            [$this, 'render_order_panel'],
            $screen,
            'side',
            'default'
        );
    }

    /**
     * @param mixed $post_or_order the post or the order, as the meta box API hands it over
     */
    public function render_order_panel($post_or_order): void {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order instanceof WP_Post ? $post_or_order->ID : $post_or_order);

        if (!$order instanceof WC_Order) {
            return;
        }

        $ledger_direct_panel = self::order_panel_values($order);

        if ($ledger_direct_panel === null) {
            echo '<p>' . esc_html__('No payment record on this order.', 'ledger-direct') . '</p>';

            return;
        }

        require LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/admin/views/order_panel_html.php';
    }

    /**
     * The panel as scalars from the presenter, or null when the order has no
     * payment record (or it is unreadable; that is logged, the page still renders).
     *
     * @return array<string, mixed>|null
     */
    public static function order_panel_values(WC_Order $order): ?array {
        $services = ServiceFactory::getInstance();

        try {
            $intent = $services->getOrderTransactionService()->readPaymentIntent($order);
        } catch (\Throwable $exception) {
            $services->getLogger()->error('LedgerDirect: could not render the order panel', [
                'order_id' => $order->get_id(),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($intent === null) {
            return null;
        }

        $transactions = [];
        try {
            $transactions = $services->getSyncService($intent->chain)->findTransactions($intent->destinationAccount, $intent->destinationTag);
        } catch (\Throwable $exception) {
            // The panel still shows the intent; the list is a bonus.
            $services->getLogger()->warning('LedgerDirect: could not list the transactions for the order panel', [
                'order_id' => $order->get_id(),
                'exception' => $exception->getMessage(),
            ]);
        }

        return OrderPanelPresenter::present($intent, $services->getSettlementPolicy(), $transactions);
    }
}