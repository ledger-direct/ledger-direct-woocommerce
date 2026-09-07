<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Port\WpdbXrplTransactionRepository;
use Hardcastle\LedgerDirect\Storage\LegacyPaymentIntentMapper;
use InvalidArgumentException;
use LedgerDirect;
use Psr\Log\LoggerInterface;
use Throwable;
use WC_Order;

/**
 * The WooCommerce side of a LedgerDirect payment: the payment record
 * (a core PaymentIntent) lives in the order's metadata under
 * {@see LedgerDirect::META_KEY}, and this service reads and writes it.
 *
 * Everything about *what* is owed - exchange rate, requested amount,
 * destination tag, matching an on-ledger payment, whether it settles -
 * comes from hardcastle/ledger-direct-core and is not recomputed here.
 */
class OrderTransactionService
{
    /** Checkout radio value → core base asset. */
    public const BASE_ASSET_BY_PAYMENT_TYPE = [
        'xrp' => 'XRP',
        'rlusd' => 'RLUSD',
        'usdc' => 'USDC',
    ];

    private PaymentIntentService $paymentIntentService;

    private SyncService $syncService;

    private SettlementPolicy $settlementPolicy;

    private LegacyPaymentIntentMapper $legacyMapper;

    private WpdbXrplTransactionRepository $transactionRepository;

    private LoggerInterface $logger;

    public function __construct(
        PaymentIntentService $paymentIntentService,
        SyncService $syncService,
        SettlementPolicy $settlementPolicy,
        LegacyPaymentIntentMapper $legacyMapper,
        WpdbXrplTransactionRepository $transactionRepository,
        LoggerInterface $logger
    ) {
        $this->paymentIntentService = $paymentIntentService;
        $this->syncService = $syncService;
        $this->settlementPolicy = $settlementPolicy;
        $this->legacyMapper = $legacyMapper;
        $this->transactionRepository = $transactionRepository;
        $this->logger = $logger;
    }

    /**
     * Quotes the order in the asset the customer chose and stores the
     * resulting PaymentIntent on the order.
     *
     * An intent already on the order is handed to the core so a repeated
     * attempt (or a refreshed quote) keeps its destination account and tag -
     * the customer may already be looking at those, or have a payment in
     * flight.
     *
     * @param string $paymentType 'xrp' | 'rlusd' | 'usdc' (checkout value), or a core base asset.
     * @throws \Hardcastle\LedgerDirect\Core\Payment\AssetNotAcceptedException
     * @throws \Hardcastle\LedgerDirect\Core\Price\PriceUnavailableException
     */
    public function prepareOrderForXrpl(WC_Order $order, string $paymentType): PaymentIntent
    {
        $baseAsset = self::BASE_ASSET_BY_PAYMENT_TYPE[strtolower($paymentType)] ?? strtoupper($paymentType);

        $intent = $this->paymentIntentService->quoteForOrder(
            (float) $order->get_total(),
            $order->get_currency(),
            $baseAsset,
            $this->readReusablePaymentIntent($order)
        );

        $this->persistPaymentIntent($order, $intent);

        return $intent;
    }

    /**
     * Re-quotes an expired, still unpaid intent in place: same destination
     * account and tag, fresh rate, amount and expiry. Returns the intent
     * to display, which is the existing one while it is valid or fulfilled.
     */
    public function refreshExpiredQuote(WC_Order $order, PaymentIntent $intent): PaymentIntent
    {
        if ($intent->amountPaid !== null || $intent->expiry === null || $intent->expiry > time()) {
            return $intent;
        }

        return $this->prepareOrderForXrpl($order, $intent->baseAsset);
    }

    /**
     * Syncs the merchant's incoming XRPL transactions and, when one matches
     * this order's destination tag, records the fulfillment on the intent.
     *
     * @param bool $sync false to only match against already-synced
     *     transactions (a batch job syncs once, then matches many orders).
     * @return PaymentIntent|null the fulfilled intent, or null while no
     *     payment has arrived (or it arrived as something that delivered
     *     nothing measurable, e.g. an EscrowCreate to the same account).
     */
    public function syncOrderTransactionWithXrpl(WC_Order $order, bool $sync = true): ?PaymentIntent
    {
        $intent = $this->readPaymentIntent($order);

        if ($intent === null) {
            return null;
        }

        if ($intent->amountPaid !== null) {
            return $intent;
        }

        if ($sync) {
            $this->syncService->syncTransactions($intent->destinationAccount, $intent->network);
        }

        [$transaction, $amountPaid] = $this->findPaymentFor($intent);

        if ($transaction === null || $amountPaid === null) {
            return null;
        }

        $fulfilledIntent = $intent->withFulfillment($transaction->hash, $amountPaid, $transaction->ctid);

        $this->persistPaymentIntent($order, $fulfilledIntent);

        return $fulfilledIntent;
    }

    /**
     * The transaction that pays this intent: the newest one on the order's
     * destination tag whose delivered amount has the shape of the quoted
     * asset (a float for XRP, an issued-currency object for a stablecoin).
     *
     * A tag can carry more than one transaction - a payment in the wrong
     * asset, or a stray payment from before the order was quoted. Those
     * are logged and skipped rather than crashing the settlement, and a
     * wrong-issuer stablecoin payment still comes through here so the
     * settlement policy can report it as such.
     *
     * @return array{0: XrplTransaction|null, 1: float|array|null}
     */
    private function findPaymentFor(PaymentIntent $intent): array
    {
        $expectsIssuedCurrency = is_array($intent->amountRequested);

        foreach ($this->transactionRepository->findTransactionsByTag($intent->destinationAccount, $intent->destinationTag) as $transaction) {
            try {
                $amountPaid = $transaction->getDeliveredAmount();
            } catch (Throwable $exception) {
                $this->logger->warning('Skipping a transaction with an unreadable delivered amount', [
                    'hash' => $transaction->hash,
                    'exception' => $exception->getMessage(),
                ]);
                continue;
            }

            if ($amountPaid === null) {
                continue;
            }

            if (is_array($amountPaid) !== $expectsIssuedCurrency) {
                $this->logger->warning('Skipping a payment in a different asset class than the one quoted', [
                    'hash' => $transaction->hash,
                    'destination_tag' => $intent->destinationTag,
                    'base_asset' => $intent->baseAsset,
                ]);
                continue;
            }

            return [$transaction, $amountPaid];
        }

        return [null, null];
    }

    /**
     * Whether the delivered amount pays for the quote - the core's decision.
     */
    public function isSettled(PaymentIntent $intent): bool
    {
        return $this->settlementPolicy->isSettled($intent);
    }

    /**
     * What is still missing, as a plain decimal string; null once settled.
     */
    public function shortfall(PaymentIntent $intent): ?string
    {
        return $this->settlementPolicy->shortfall($intent);
    }

    /**
     * The payment record stored on the order, or null when the order was
     * never prepared for XRPL. Records written by plugin versions before
     * the core retrofit are translated on the way in.
     *
     * @throws InvalidArgumentException on a record that is not readable as
     *     a schema v1 PaymentIntent even after translation.
     */
    public function readPaymentIntent(WC_Order $order): ?PaymentIntent
    {
        $data = $order->get_meta(LedgerDirect::META_KEY);

        if (!is_array($data) || $data === []) {
            return null;
        }

        if ($this->legacyMapper->isLegacy($data)) {
            $data = $this->legacyMapper->toSchemaV1($data);
        }

        return PaymentIntent::fromArray($data);
    }

    /**
     * Like readPaymentIntent(), but for the quoting path, where an
     * unreadable record simply means "quote from scratch".
     */
    private function readReusablePaymentIntent(WC_Order $order): ?PaymentIntent
    {
        try {
            return $this->readPaymentIntent($order);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Writes the intent to the order, replacing any previous record
     * wholesale so no field of an older shape can survive underneath.
     */
    private function persistPaymentIntent(WC_Order $order, PaymentIntent $intent): void
    {
        $order->update_meta_data(LedgerDirect::META_KEY, $intent->toArray());
        $order->save();
    }
}
