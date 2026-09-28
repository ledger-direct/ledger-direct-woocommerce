<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Presentation;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\PaymentUri;
use Hardcastle\LedgerDirect\Core\Presentation\AccentColor;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplAmount;

/**
 * Turns a PaymentIntent into the plain scalars the payment page renders.
 *
 * Lives in src/ on purpose: the release build prefixes the core's namespace
 * (PHP-Scoper), so only code in here may name a core class. The view under
 * includes/ gets an array of scalars and nothing else.
 *
 * Nothing here rounds. Every amount is the core's plain decimal -
 * PaymentIntent::amountRequestedValue(), amountPaidValue(), the settlement
 * policy's shortfall - so the page, the QR code, the wallet module and the
 * poll all show one and the same number. The one computation is the progress
 * bar's width, never shown as a number; the one formatting is the exchange
 * rate's, which is not an amount.
 */
final class PaymentPagePresenter
{
    private const EXPLORER = [
        'mainnet' => 'https://livenet.xrpl.org/transactions/',
        'testnet' => 'https://testnet.xrpl.org/transactions/',
    ];

    /** Decimals of the exchange rate: an oracle average with a float tail, cosmetic beyond a few places. */
    private const RATE_DECIMALS = 6;

    /**
     * @param array{
     *   order_number: string, total: float|string, shop_currency: string, fiat_display: string,
     *   quote_minutes: int, poll_url: string, page_url: string, redirect_url: string, cart_url: string, home_url: string,
     *   store_name: string, page_title: string, accent: string, logo: array{mode: string, url: string|null, monogram: string},
     *   xaman_key: string, wc_project: string, wallets_src: string, refresh_nonce: string, order_key: string
     * } $platform what only the platform knows - read by the caller from the order and the settings
     *
     * @return array<string, mixed>
     */
    public static function present(PaymentIntent $intent, PaymentStatus $status, ?string $shortfall, array $platform): array
    {
        $state = $status->state();
        $amountRequested = $intent->amountRequestedValue();
        $amountPaid = $intent->amountPaidValue();
        $isToken = is_array($intent->amountRequested);
        $amountDue = $state === PaymentStatus::PARTIAL && $shortfall !== null ? $shortfall : $amountRequested;
        $paymentUri = PaymentUri::forIntent($intent, $amountDue);

        // The presenter's values win over the platform's where both name a key (accent: sanitised here).
        return array_merge($platform, [
            'state' => $state,
            'seconds_left' => $status->secondsLeft,
            'has_expiry' => $intent->expiry !== null,
            'quote_seconds' => max(60, (int) $platform['quote_minutes'] * 60),
            'asset' => $intent->baseAsset,
            'network' => $intent->network,
            'is_testnet' => $intent->network !== 'mainnet',
            'explorer_base' => self::EXPLORER[$intent->network] ?? self::EXPLORER['testnet'],
            'destination_account' => $intent->destinationAccount,
            'destination_tag' => $intent->destinationTag,
            'issuer' => $isToken ? (string) ($intent->amountRequested['issuer'] ?? '') : null,
            'currency' => $isToken ? (string) ($intent->amountRequested['currency'] ?? '') : null,
            'amount' => $amountRequested,
            'amount_paid' => $amountPaid,
            'shortfall' => $shortfall,
            'amount_due' => $amountDue,
            'amount_drops' => $isToken ? null : XrplAmount::xrpToDrops($amountDue),
            'payment_uri' => $paymentUri,
            'qr_data_uri' => self::qrDataUri($paymentUri),
            'paid_share' => self::paidShare($amountPaid, $amountRequested, $state),
            'rate_display' => self::rate($intent->exchangeRate),
            'hash' => $intent->hash,
            'accent' => AccentColor::sanitize($platform['accent']),
        ]);
    }

    /**
     * The exchange rate as a plain decimal: at most six places, no trailing zeros, no exponent.
     */
    public static function rate(float $rate): string
    {
        $decimal = number_format($rate, self::RATE_DECIMALS, '.', '');

        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    /**
     * The first letter of the store name, upper-cased, multibyte-safe; a placeholder when there is none.
     */
    public static function monogram(string $storeName): string
    {
        $trimmed = trim($storeName);

        if ($trimmed === '') {
            return '·';
        }

        return mb_strtoupper(mb_substr($trimmed, 0, 1));
    }

    /**
     * The progress bar's width in whole per cent - only a partial payment has one.
     */
    private static function paidShare(?string $amountPaid, string $amountRequested, string $state): int
    {
        if ($state !== PaymentStatus::PARTIAL || $amountPaid === null || (float) $amountRequested <= 0.0) {
            return 0;
        }

        return (int) max(0, min(100, floor((float) $amountPaid / (float) $amountRequested * 100)));
    }

    /**
     * The QR code the page shows without JavaScript: the same payment request the
     * script renders, as an inline SVG; the script redraws it.
     */
    private static function qrDataUri(string $paymentUri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd()));

        return 'data:image/svg+xml;base64,' . base64_encode($writer->writeString($paymentUri));
    }
}
