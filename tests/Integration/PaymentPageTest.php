<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Api\PaymentStatusEndpoint;
use Hardcastle\LedgerDirect\Port\WpConfigProvider;
use Hardcastle\LedgerDirect\Presentation\PaymentPagePresenter;
use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Hardcastle\LedgerDirect\Service\ServiceFactory;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;
use LedgerDirect;
use WC_Order;

/**
 * The payment page as a customer's browser receives it: through the real template
 * filter, with the settings and the order a checkout leaves behind.
 *
 * What it proves is the markup contract of @ledger-direct/payment-ui - the root
 * attributes the script and the end-to-end harness read, the amount exactly as the
 * core states it, the package assets enqueued on this page and nowhere else.
 */
class PaymentPageTest extends TestCase
{
    private WC_Order $order;

    private XrplNetworkStub $network;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ledger_direct_xrpl_tx");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ledger_direct_xrpl_destination_tag");
        delete_transient('ledger_direct_ledger-direct.rate.v1.testnet.XRP.EUR');
        delete_transient('ledger_direct_ledger-direct.sync.v1.testnet.' . XrplNetworkStub::ACCOUNT);

        update_option(WpConfigProvider::OPTION_NAME, [
            'enabled' => 'yes',
            WpConfigProvider::KEY_NETWORK => 'testnet',
            WpConfigProvider::KEY_TESTNET_ACCOUNT => XrplNetworkStub::ACCOUNT,
            WpConfigProvider::KEY_RLUSD_ENABLED => 'no',
            WpConfigProvider::KEY_USDC_ENABLED => 'no',
            WpConfigProvider::KEY_QUOTE_EXPIRY => '15',
            'xrpl_page_accent' => '#123456',
            'xrpl_page_logo_mode' => 'none',
        ]);

        $this->network = new XrplNetworkStub(xrpRate: 0.5, stablecoinRate: 0.9);
        $this->network->install();
        ServiceFactory::setInstance(null);

        $this->order = wc_create_order();
        $this->order->set_currency('EUR');
        $this->order->set_total('100');
        $this->order->set_payment_method(LedgerDirectPaymentGateway::ID);
        $this->order->set_status('pending');
        $this->order->save();
        ServiceFactory::getInstance()->getOrderTransactionService()->prepareOrderForXrpl($this->order, 'xrp');
    }

    protected function tearDown(): void
    {
        $this->network->remove();
        ServiceFactory::setInstance(null);
        delete_transient('ledger_direct_ledger-direct.sync.v1.testnet.' . XrplNetworkStub::ACCOUNT);

        parent::tearDown();
    }

    public function testThePageRendersTheMarkupContractForAWaitingOrder(): void
    {
        $html = $this->renderPage();
        $intent = ServiceFactory::getInstance()->getOrderTransactionService()->readPaymentIntent($this->order);

        self::assertStringContainsString('data-ld-state="waiting"', $html);
        self::assertStringContainsString('data-ld-amount-requested="' . $intent->amountRequestedValue() . '"', $html);
        self::assertStringContainsString('data-ld-asset="XRP"', $html);
        self::assertStringContainsString('data-ld-quote-seconds="900"', $html);
        self::assertStringContainsString('data-ld-account data-value="' . XrplNetworkStub::ACCOUNT . '"', $html);
        self::assertStringContainsString('data-ld-tag data-value="' . $intent->destinationTag . '"', $html);
        self::assertStringContainsString('data-ld-poll-url="', $html);
        self::assertStringContainsString('ledger-direct-payment-ui/wallets.js"', $html);
        self::assertStringContainsString('style="--ld-accent: #123456"', $html);
        self::assertMatchesRegularExpression('~data-ld-amount>' . preg_quote($intent->amountRequestedValue(), '~') . '<~', $html);
        self::assertStringContainsString('data-ld-check-form', $html);
        self::assertStringContainsString('data-ld-success', $html);
        self::assertStringContainsString('data:image/svg+xml;base64,', $html);
        // No platform ids the contract does not know
        self::assertStringNotContainsString('id="xrp-amount"', $html);
        self::assertStringNotContainsString('id="destination-account"', $html);
    }

    public function testThePackageAssetsAreEnqueuedOnThePageOnly(): void
    {
        $plugin = LedgerDirect::instance();

        // Not on every page any more: nothing is hooked to wp_enqueue_scripts
        self::assertFalse(has_action('wp_enqueue_scripts', [$plugin, 'enqueue_public_scripts']));
        self::assertFalse(has_action('wp_enqueue_scripts', [$plugin, 'enqueue_public_styles']));

        $plugin->enqueue_public_styles();
        $plugin->enqueue_public_scripts();

        self::assertTrue(wp_style_is('ledger-direct-payment-ui', 'enqueued'));
        self::assertTrue(wp_script_is('ledger-direct-payment-ui', 'enqueued'));
        self::assertFalse(wp_script_is('jquery-qrcode', 'enqueued'));
        self::assertFalse(wp_script_is('ledger-direct', 'enqueued'));
        // The wallet library is fetched by the page on demand, never enqueued
        self::assertStringNotContainsString('wallets.js', (string) wp_scripts()->registered['ledger-direct-payment-ui']->src);
    }

    /**
     * The view with the presenter's array, the way render_payment_page() hands it over -
     * without the controller, whose redirects exit the process.
     */
    private function renderPage(): string
    {
        global $ledger_direct_order, $ledger_direct_view;

        $service = ServiceFactory::getInstance()->getOrderTransactionService();
        $intent = $service->readPaymentIntent($this->order);
        $configuration = new ConfigurationService();

        $ledger_direct_order = $this->order;
        $ledger_direct_view = PaymentPagePresenter::present($intent, $service->paymentStatus($intent), $service->shortfall($intent), [
            'order_number' => (string) $this->order->get_order_number(),
            'order_key' => (string) $this->order->get_order_key(),
            'total' => (string) $this->order->get_total(),
            'shop_currency' => (string) $this->order->get_currency(),
            'fiat_display' => '€100.00',
            'quote_minutes' => 15,
            'poll_url' => PaymentStatusEndpoint::url($this->order),
            'page_url' => LedgerDirectPaymentGateway::get_payment_page_url($this->order),
            'redirect_url' => 'https://shop.test/order-received/',
            'cart_url' => wc_get_cart_url(),
            'home_url' => home_url('/'),
            'store_name' => (string) get_bloginfo('name'),
            'page_title' => 'Pay',
            'accent' => $configuration->getPaymentPageAccentColor(),
            'logo' => ['mode' => 'none', 'url' => null, 'monogram' => 'T'],
            'xaman_key' => $configuration->getXamanApiKey(),
            'wc_project' => $configuration->getWalletConnectProjectId(),
            'wallets_src' => ledger_direct_get_public_url('/public/js/ledger-direct-payment-ui/wallets.js'),
            'refresh_nonce' => 'n',
        ]);

        ob_start();
        include LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/views/ledger-direct_html.php';

        return (string) ob_get_clean();
    }
}
