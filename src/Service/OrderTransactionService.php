<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use Hardcastle\LedgerDirect\Storage\LegacyPaymentIntentMapper;
use InvalidArgumentException;
use LedgerDirect;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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

    private ?SyncThrottle $syncThrottle;

    private LoggerInterface $logger;

    public function __construct(
        PaymentIntentService $paymentIntentService,
        SyncService $syncService,
        SettlementPolicy $settlementPolicy,
        LegacyPaymentIntentMapper $legacyMapper,
        ?SyncThrottle $syncThrottle = null,
        ?LoggerInterface $logger = null
    ) {
        $this->paymentIntentService = $paymentIntentService;
        $this->syncService = $syncService;
        $this->settlementPolicy = $settlementPolicy;
        $this->legacyMapper = $legacyMapper;
        $this->syncThrottle = $syncThrottle;
        $this->logger = $logger ?? new NullLogger();
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
     * Syncs the merchant's incoming XRPL transactions and, when they pay
     * this order, records the fulfillment on the intent.
     *
     * What pays is the core's decision: every payment in the quoted asset on
     * the tag counts and they add up, so a top-up of a shortfall settles; a
     * payment in another asset is the fulfillment only while nothing in the
     * right one has arrived, so the page can say "wrong token". The intent
     * records the hash and ctid of the newest contributing transaction.
     *
     * A fulfillment that does not settle is stored too - the page shows the
     * shortfall from it - and the order keeps being matched until one does.
     * Only a settled intent is final; it is returned as stored, without a
     * node request.
     *
     * @param bool $sync false to only match against already-synced
     *     transactions (a batch job syncs once, then matches many orders).
     * @return PaymentIntent|null the fulfilled intent (settled or not, see
     *     isSettled()), or null while nothing payable has arrived: no
     *     transaction on the tag yet, or only ones the core skips and logs.
     */
    public function syncOrderTransactionWithXrpl(WC_Order $order, bool $sync = true): ?PaymentIntent
    {
        $intent = $this->readPaymentIntent($order);

        if ($intent === null) {
            return null;
        }

        if ($intent->hash !== null && $this->settlementPolicy->isSettled($intent)) {
            return $intent;
        }

        if ($sync) {
            $this->syncService->syncTransactions($intent->destinationAccount, $intent->network);
        }

        $fulfilledIntent = $this->syncService->findFulfillmentFor($intent)?->applyTo($intent);

        if ($fulfilledIntent === null) {
            return null;
        }

        // Save only what changed: a partial payment that is polled again and
        // again would otherwise rewrite the same record on every request.
        if ($fulfilledIntent->toArray() !== $intent->toArray()) {
            $this->persistPaymentIntent($order, $fulfilledIntent);
        }

        return $fulfilledIntent;
    }

    /**
     * The customer-facing path: the payment page and the status endpoint.
     *
     * Syncs the receiving account at most once per interval, however many
     * pages poll it (SyncThrottle, keyed by network and account), then
     * matches against the local table. The mark is set before the request:
     * a node that does not answer is otherwise hit by every poll. A sync
     * failure is logged, not thrown - the poll still answers from what is
     * stored, and the next poll or the background job tries again.
     *
     * The background job does not come through here; it syncs once per
     * account per run, unthrottled, and matches many orders.
     */
    public function syncOrderTransactionThrottled(WC_Order $order): ?PaymentIntent
    {
        $intent = $this->readPaymentIntent($order);

        if ($intent === null) {
            return null;
        }

        $due = $this->syncThrottle === null
            || $this->syncThrottle->shouldSync($intent->network, $intent->destinationAccount);

        if ($due && !($intent->hash !== null && $this->settlementPolicy->isSettled($intent))) {
            $this->syncThrottle?->markSynced($intent->network, $intent->destinationAccount);

            try {
                $this->syncService->syncTransactions($intent->destinationAccount, $intent->network);
            } catch (Throwable $exception) {
                $this->logger->warning('LedgerDirect: ledger sync failed, matching against the stored transactions', [
                    'order_id' => $order->get_id(),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $this->syncOrderTransactionWithXrpl($order, false);
    }

    /**
     * The answer to "is this order paid?" - one of the five states of the
     * payment-status contract, derived from the stored intent and the
     * core's policy (INVARIANTS.md, "Payment status").
     */
    public function paymentStatus(PaymentIntent $intent): PaymentStatus
    {
        return PaymentStatus::fromIntent($intent, $this->settlementPolicy);
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
