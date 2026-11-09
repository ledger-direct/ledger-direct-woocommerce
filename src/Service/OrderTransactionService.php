<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Chain\SupportedChains;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Storage\LegacyPaymentIntentMapper;
use Automattic\WooCommerce\Caches\OrderCache;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Hardcastle\LedgerDirect\Woocommerce\PaymentIncompleteStatus;
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
 * comes from hardcastle/ledger-direct-core and is not recomputed here. The
 * core's services come per chain from the {@see ServiceFactory}: the payment
 * type names the chain an order is quoted on, the stored intent the chain it
 * is synced and matched on.
 */
class OrderTransactionService
{
    private ServiceFactory $services;

    private SettlementPolicy $settlementPolicy;

    private LegacyPaymentIntentMapper $legacyMapper;

    private LoggerInterface $logger;

    public function __construct(
        ServiceFactory $services,
        SettlementPolicy $settlementPolicy,
        LegacyPaymentIntentMapper $legacyMapper,
        ?LoggerInterface $logger = null
    ) {
        $this->services = $services;
        $this->settlementPolicy = $settlementPolicy;
        $this->legacyMapper = $legacyMapper;
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
     * @param string $paymentType 'xrp' | 'rlusd' | 'usdc' | 'xah' (checkout value), or a core base asset.
     * @throws InvalidArgumentException for a payment type no carried chain has
     * @throws \Hardcastle\LedgerDirect\Core\Payment\AssetNotAcceptedException
     * @throws \Hardcastle\LedgerDirect\Core\Price\PriceUnavailableException
     */
    public function prepareOrderForXrpl(WC_Order $order, string $paymentType): PaymentIntent
    {
        $chainAndAsset = SupportedChains::chainAndAssetFor($paymentType);
        if ($chainAndAsset === null) {
            throw new InvalidArgumentException('Unsupported payment type: ' . esc_html($paymentType));
        }
        [$chain, $asset] = $chainAndAsset;

        $intent = $this->services->getPaymentIntentService($chain->chain)->quoteForOrder(
            (float) $order->get_total(),
            $order->get_currency(),
            $asset->code,
            $this->readReusablePaymentIntent($order, $chain->chain)
        );

        $this->persistPaymentIntent($order, $intent);

        return $intent;
    }

    /**
     * Re-quotes an expired, still unpaid intent in place: same destination
     * account and tag, fresh rate, amount and expiry. Returns the intent
     * to display, which is the existing one while it is valid or fulfilled.
     */
    /**
     * Marks the order paid, exactly once. Every path to "paid" comes through here.
     *
     * Holds a per-order database lock from the check to the booking and reads the order again
     * inside it, so the decision does not rest on an object loaded earlier. Without the lock
     * another run is booking this order right now: nothing to do, the next status check reports
     * the result. A server that cannot give the lock at all (GET_LOCK answers NULL) must not leave
     * paid orders open, so the booking then goes ahead unprotected, with a warning.
     *
     * @return bool whether this call booked the order
     */
    public function completePayment(WC_Order $order, PaymentIntent $fulfilled): bool
    {
        global $wpdb;

        $lock = self::paymentLockName($order->get_id());
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock));

        if ($acquired === '0') {
            return false;
        }

        if ($acquired === null) {
            $this->logger->warning('LedgerDirect: no database lock available, booking the payment without one', [
                'order_id' => $order->get_id(),
            ]);
        }

        try {
            $current = $this->freshOrder($order->get_id());

            if (!$current instanceof WC_Order || !$current->needs_payment()) {
                return false;
            }

            $current->payment_complete((string) $fulfilled->hash);

            return true;
        } finally {
            if ($acquired !== null) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }

    /**
     * The lock is server-wide, so its name carries the database and table prefix as a digest:
     * two shops on one database server must not block each other. MySQL allows 64 characters.
     */
    public static function paymentLockName(int $orderId): string
    {
        global $wpdb;

        return 'ld_pay_' . substr(md5(DB_NAME . '|' . $wpdb->prefix), 0, 12) . '_' . $orderId;
    }

    /**
     * The order as the database has it now, past this request's object caches.
     */
    private function freshOrder(int $orderId): WC_Order|false
    {
        clean_post_cache($orderId);

        if (class_exists(OrderCache::class) && OrderUtil::orders_cache_usage_is_enabled()) {
            wc_get_container()->get(OrderCache::class)->remove($orderId);
        }

        $order = wc_get_order($orderId);

        return $order instanceof WC_Order ? $order : false;
    }

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

        $syncService = $this->services->getSyncService($intent->chain);
        if ($sync) {
            $syncService->syncTransactions($intent->destinationAccount, $intent->network);
        }

        $fulfilledIntent = $syncService->findFulfillmentFor($intent)?->applyTo($intent);

        if ($fulfilledIntent === null) {
            return null;
        }

        // Save only what changed: a partial payment that is polled again and
        // again would otherwise rewrite the same record on every request.
        if ($fulfilledIntent->toArray() !== $intent->toArray()) {
            $this->persistPaymentIntent($order, $fulfilledIntent);

            if (!$this->settlementPolicy->isSettled($fulfilledIntent)) {
                $this->markIncomplete($order, $fulfilledIntent);
            }
        }

        return $fulfilledIntent;
    }

    /**
     * Moves the order to "XRPL payment incomplete" when something arrives
     * that does not pay it, with a note saying what arrived and what is
     * still due. Once per change: a second short payment that still falls
     * short adds a second note, a poll that sees nothing new adds none.
     *
     * The status is a courtesy to the merchant, not something the
     * settlement depends on: the intent is saved either way, and the order
     * keeps being matched until the ledger covers the amount.
     */
    private function markIncomplete(WC_Order $order, PaymentIntent $fulfilled): void
    {
        $asset = $fulfilled->baseAsset;
        $hash = (string) $fulfilled->hash;

        if ($this->settlementPolicy->isWrongAsset($fulfilled)) {
            $note = sprintf(
                /* translators: 1: amount requested, 2: asset, 3: transaction hash */
                __('A payment arrived in another token and was not credited. The full %1$s %2$s is still due. Transaction %3$s.', 'ledger-direct'),
                $fulfilled->amountRequestedValue(),
                $asset,
                $hash
            );
        } else {
            $note = sprintf(
                /* translators: 1: amount received, 2: asset, 3: amount requested, 4: amount still due, 5: transaction hash */
                __('A payment arrived that does not settle the order: %1$s %2$s received of %3$s %2$s requested, %4$s %2$s still due. Transaction %5$s.', 'ledger-direct'),
                (string) $fulfilled->amountPaidValue(),
                $asset,
                $fulfilled->amountRequestedValue(),
                (string) $this->settlementPolicy->shortfall($fulfilled),
                $hash
            );
        }

        try {
            if ($order->has_status(PaymentIncompleteStatus::STATUS)) {
                $order->add_order_note($note);
            } else {
                $order->update_status(PaymentIncompleteStatus::STATUS, $note);
            }
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: could not mark the order as incompletely paid', [
                'order_id' => $order->get_id(),
                'exception' => $exception->getMessage(),
            ]);
        }
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

        $throttle = $this->services->getSyncThrottle($intent->chain);
        $due = $throttle->shouldSync($intent->network, $intent->destinationAccount);

        if ($due && !($intent->hash !== null && $this->settlementPolicy->isSettled($intent))) {
            $throttle->markSynced($intent->network, $intent->destinationAccount);

            try {
                $this->services->getSyncService($intent->chain)->syncTransactions($intent->destinationAccount, $intent->network);
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
    /**
     * The stored intent, when it is one the new quote may continue: on the same chain, so
     * the destination tag it keeps was reserved in that chain's counter. An order paid for
     * again with another chain's asset starts over - the same address may receive on the
     * XRP Ledger and on Xahau, and a tag reused across the two could collide with another
     * order's there.
     */
    private function readReusablePaymentIntent(WC_Order $order, string $chain): ?PaymentIntent
    {
        try {
            $intent = $this->readPaymentIntent($order);

            return $intent !== null && $intent->chain === $chain ? $intent : null;
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
