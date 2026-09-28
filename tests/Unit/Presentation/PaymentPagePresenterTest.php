<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Presentation;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Presentation\PaymentPagePresenter;
use PHPUnit\Framework\TestCase;

/**
 * What the view gets to see, per payment state: the numbers a customer types into a
 * wallet come out exactly as the core states them, and the payment request behind the
 * QR code follows the amount to send.
 */
class PaymentPagePresenterTest extends TestCase
{
    private const ACCOUNT = 'raXkRCAYkqaoFYCeVej93SzCTtiAbbRzAg';
    private const NOW = 1_700_000_000;

    public function testAWaitingXrpOrderShowsTheRequestInDropsAndInTheQrRequest(): void
    {
        $view = $this->present($this->xrpIntent(0.83211, self::NOW + 200));

        self::assertSame('waiting', $view['state']);
        self::assertSame(200, $view['seconds_left']);
        self::assertSame('0.83211', $view['amount']);
        self::assertSame('0.83211', $view['amount_due']);
        self::assertSame('832110', $view['amount_drops']);
        self::assertNull($view['currency']);
        self::assertSame('EUR', $view['shop_currency']);
        self::assertSame('https://xrplf.org//send?to=' . self::ACCOUNT . '&dt=123456&amount=0.83211', $view['payment_uri']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $view['qr_data_uri']);
        self::assertSame('XRP', $view['asset']);
        self::assertSame(900, $view['quote_seconds']);
        self::assertSame('https://testnet.xrpl.org/transactions/', $view['explorer_base']);
        self::assertSame(0, $view['paid_share']);
        self::assertSame('#1f5eff', $view['accent']);
    }

    public function testAPartialPaymentAsksForTheShortfall(): void
    {
        $intent = $this->xrpIntent(15.06378, self::NOW + 200)->withFulfillment('AA', 5.0, 'C1');
        $view = $this->present($intent);

        self::assertSame('partial', $view['state']);
        self::assertSame('5', $view['amount_paid']);
        self::assertSame('10.06378', $view['shortfall']);
        self::assertSame('10.06378', $view['amount_due']);
        self::assertSame('10063780', $view['amount_drops']);
        self::assertStringContainsString('&amount=10.06378', $view['payment_uri']);
        self::assertSame(33, $view['paid_share']);
    }

    public function testATokenOrderCarriesCurrencyAndIssuerAndTheQuotedValue(): void
    {
        $intent = PaymentIntent::quote(
            type: 'usdc-payment', chain: 'XRPL', network: 'mainnet', baseAsset: 'USDC', quoteCurrency: 'EUR',
            pairing: 'USDC/EUR', exchangeRate: 0.92,
            amountRequested: ['currency' => '5553444300000000000000000000000000000000', 'value' => '12.50', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt'],
            destinationAccount: self::ACCOUNT, destinationTag: 123456, expiry: self::NOW + 200,
        );
        $view = $this->present($intent);

        self::assertSame('USDC', $view['asset']);
        self::assertSame('12.50', $view['amount']);
        self::assertNull($view['amount_drops']);
        self::assertSame('5553444300000000000000000000000000000000', $view['currency']);
        self::assertSame('rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt', $view['issuer']);
        self::assertStringContainsString('&amount=12.50&currency=5553444300000000000000000000000000000000&issuer=rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt', $view['payment_uri']);
        self::assertSame('https://livenet.xrpl.org/transactions/', $view['explorer_base']);
        self::assertFalse($view['is_testnet']);
    }

    public function testAnAccentColourTooLightForWhiteTextFallsBackToTheDefault(): void
    {
        $view = $this->present($this->xrpIntent(1.0, self::NOW + 200), ['accent' => '#fff59d']);

        self::assertSame('#1f5eff', $view['accent']);
    }

    public function testTheRateIsAPlainDecimalAndTheOnlyFormattedNumber(): void
    {
        self::assertSame('1.201763', PaymentPagePresenter::rate(1.2017633333333));
        self::assertSame('0.00001', PaymentPagePresenter::rate(0.00001));
        self::assertSame('2', PaymentPagePresenter::rate(2.0));
    }

    public function testTheMonogramIsTheFirstLetterUpperCased(): void
    {
        self::assertSame('Ö', PaymentPagePresenter::monogram('  öko-laden'));
        self::assertSame('·', PaymentPagePresenter::monogram('   '));
    }

    private function xrpIntent(float $amount, ?int $expiry): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'XRP', quoteCurrency: 'EUR',
            pairing: 'XRP/EUR', exchangeRate: 1.27, amountRequested: $amount,
            destinationAccount: self::ACCOUNT, destinationTag: 123456, expiry: $expiry,
        );
    }

    /**
     * @param array<string, mixed> $platform overrides of what the platform supplies
     * @return array<string, mixed>
     */
    private function present(PaymentIntent $intent, array $platform = []): array
    {
        $policy = new SettlementPolicy();
        $status = PaymentStatus::fromIntent($intent, $policy, self::NOW);

        return PaymentPagePresenter::present($intent, $status, $policy->shortfall($intent), $platform + [
            'order_number' => '77', 'order_key' => 'wc_order_k', 'total' => '1.00', 'shop_currency' => 'EUR', 'fiat_display' => '€1.00',
            'quote_minutes' => 15, 'poll_url' => 'https://shop.test/wp-json/ledger-direct/v1/payment-status/wc_order_k',
            'page_url' => 'https://shop.test/ledger-direct-payment/wc_order_k/', 'redirect_url' => 'https://shop.test/checkout/order-received/77/',
            'cart_url' => 'https://shop.test/cart/', 'home_url' => 'https://shop.test/', 'store_name' => 'Fixture Shop', 'page_title' => 'Pay',
            'accent' => '#1f5eff', 'logo' => ['mode' => 'none', 'url' => null, 'monogram' => 'F'], 'xaman_key' => '', 'wc_project' => '',
            'wallets_src' => 'https://shop.test/wp-content/plugins/ledger-direct/public/js/ledger-direct-payment-ui/wallets.js', 'refresh_nonce' => 'n',
        ]);
    }
}
