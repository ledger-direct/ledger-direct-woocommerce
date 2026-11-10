<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Api\PaymentStatusEndpoint;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Port\WpConfigProvider;
use Hardcastle\LedgerDirect\Presentation\PaymentPagePresenter;
use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Hardcastle\LedgerDirect\Service\ServiceFactory;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;
use Hardcastle\LedgerDirect\Woocommerce\PaymentIncompleteStatus;
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
        delete_transient(XrplNetworkStub::throttleTransient());

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
        delete_transient(XrplNetworkStub::throttleTransient());

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

    /**
     * The view used to list the statuses it would render for by name, so an
     * order a short payment had moved to "XRPL payment incomplete" got
     * "Payment not required - order is already XRPL payment incomplete"
     * instead of its page. The rule is WooCommerce's needs_payment().
     */
    public function testAnIncompletelyPaidOrderStillGetsItsPaymentPage(): void
    {
        $this->order->set_status(PaymentIncompleteStatus::STATUS);
        $this->order->save();
        $this->order = wc_get_order($this->order->get_id());

        $html = $this->renderPage();

        self::assertStringContainsString('data-ld-state="waiting"', $html);
        self::assertStringNotContainsString('Payment not required', $html);
    }

    public function testAPaidOrderGetsTheNoticeInsteadOfThePage(): void
    {
        $this->order->set_status('processing');
        $this->order->save();
        $this->order = wc_get_order($this->order->get_id());

        $html = $this->renderPage();

        self::assertStringContainsString('Payment not required', $html);
        self::assertStringNotContainsString('data-ld-state=', $html);
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
     * A refresh that gets no price - the oracles disagree or none answers - keeps
     * the expired quote and sends the customer back with the flag the page reads.
     */
    public function testARefreshWithoutAPriceKeepsTheQuoteAndFlagsThePage(): void
    {
        $expired = $this->expireQuote();
        $this->withoutPrices();

        $url = LedgerDirect::instance()->refresh_quote($this->order, $expired);

        self::assertStringStartsWith(LedgerDirectPaymentGateway::get_payment_page_url($this->order), $url);
        self::assertStringContainsString(LedgerDirect::PRICE_UNAVAILABLE_FLAG . '=1', $url);
        $stored = ServiceFactory::getInstance()->getOrderTransactionService()->readPaymentIntent($this->order);
        self::assertSame($expired->toArray(), $stored->toArray(), 'the expired quote is left as it was');
    }

    /**
     * Any other failure keeps the old behaviour: logged, back to the page, no price notice.
     */
    public function testARefreshFailingForAnotherReasonDoesNotClaimAMissingPrice(): void
    {
        $expired = $this->expireQuote();
        // RLUSD is switched off in setUp(): the core refuses the asset, a failure that is not about the price.
        $broken = PaymentIntent::fromArray(array_merge($expired->toArray(), [
            'base_asset' => 'RLUSD',
            'amount_requested' => ['currency' => '524C555344000000000000000000000000000000', 'issuer' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De', 'value' => '10'],
        ]));

        $url = LedgerDirect::instance()->refresh_quote($this->order, $broken);

        self::assertSame(LedgerDirectPaymentGateway::get_payment_page_url($this->order), $url);
    }

    /**
     * Back on the page after the redirect: still expired, still the button to try
     * again, and a sentence saying why nothing changed.
     */
    public function testTheExpiredPageSaysThePriceIsTemporarilyUnavailable(): void
    {
        $this->expireQuote();
        $notice = 'A new amount is temporarily unavailable';

        $_GET[LedgerDirect::PRICE_UNAVAILABLE_FLAG] = '1';
        try {
            $html = $this->renderPage(['price_unavailable' => LedgerDirect::price_unavailable_requested()]);
        } finally {
            unset($_GET[LedgerDirect::PRICE_UNAVAILABLE_FLAG]);
        }

        self::assertStringContainsString('data-ld-state="expired"', $html);
        self::assertStringContainsString('name="ledger_direct_refresh"', $html);
        self::assertStringContainsString($notice, $html);

        // Without the flag the expired page is as it was.
        self::assertStringNotContainsString($notice, $this->renderPage(['price_unavailable' => LedgerDirect::price_unavailable_requested()]));
    }

    private function expireQuote(): PaymentIntent
    {
        $service = ServiceFactory::getInstance()->getOrderTransactionService();
        $data = $service->readPaymentIntent($this->order)->toArray();
        $data['expiry'] = time() - 10;
        $this->order->update_meta_data(LedgerDirect::META_KEY, $data);
        $this->order->save();

        return $service->readPaymentIntent($this->order);
    }

    /**
     * Every price oracle fails and no rate is cached: the core has no price to quote.
     */
    private function withoutPrices(): void
    {
        delete_transient('ledger_direct_ledger-direct.rate.v1.testnet.XRP.EUR');
        add_filter('pre_http_request', static function ($preempt, array $args, string $url) {
            if (str_contains($url, 'binance.com') || str_contains($url, 'coingecko.com') || str_contains($url, 'kraken.com')) {
                return ['headers' => [], 'body' => '', 'response' => ['code' => 503, 'message' => 'Service Unavailable'], 'cookies' => [], 'filename' => null];
            }

            return $preempt;
        }, 20, 3); // after the network stub, which answers every request at priority 10
    }

    /**
     * The view with the presenter's array, the way render_payment_page() hands it over -
     * without the controller, whose redirects exit the process.
     */
    /**
     * @param array<string, mixed> $platform platform values beyond the defaults below
     */
    private function renderPage(array $platform = []): string
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
        ] + $platform);

        ob_start();
        include LEDGER_DIRECT_PLUGIN_FILE_PATH . 'includes/views/ledger-direct_html.php';

        return (string) ob_get_clean();
    }
}
