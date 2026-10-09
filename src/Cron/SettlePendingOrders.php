<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Cron;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Service\ServiceFactory;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;
use Hardcastle\LedgerDirect\Woocommerce\PaymentIncompleteStatus;
use Throwable;
use WC_Order;

/**
 * Settles LedgerDirect orders in the background, so a customer who closes
 * the payment page after sending funds still gets their order marked paid.
 * Runs on WooCommerce's Action Scheduler every five minutes, like the
 * Magento and PrestaShop plugins' cron jobs.
 */
final class SettlePendingOrders
{
    public const HOOK = 'ledger_direct_settle_pending_orders';

    public const GROUP = 'ledger-direct';

    public const INTERVAL_SECONDS = 5 * MINUTE_IN_SECONDS;

    private const BATCH_SIZE = 25;

    /** Rechecking the schedule on every request would be a query per request. */
    private const SCHEDULED_TRANSIENT = 'ledger_direct_settlement_scheduled';

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
        add_action('init', [self::class, 'schedule']);
    }

    public static function schedule(): void
    {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return;
        }

        if (get_transient(self::SCHEDULED_TRANSIENT)) {
            return;
        }

        if (!as_has_scheduled_action(self::HOOK, [], self::GROUP)) {
            as_schedule_recurring_action(time() + self::INTERVAL_SECONDS, self::INTERVAL_SECONDS, self::HOOK, [], self::GROUP);
        }

        set_transient(self::SCHEDULED_TRANSIENT, 1, HOUR_IN_SECONDS);
    }

    public static function unschedule(): void
    {
        delete_transient(self::SCHEDULED_TRANSIENT);

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, [], self::GROUP);
        }
    }

    public static function run(): void
    {
        $factory = ServiceFactory::getInstance();
        $service = $factory->getOrderTransactionService();
        $logger = $factory->getLogger();

        $orders = wc_get_orders([
            // Pending, and the orders a short or wrong payment already moved on.
            'status' => ['pending', PaymentIncompleteStatus::STATUS],
            'payment_method' => LedgerDirectPaymentGateway::ID,
            'limit' => self::BATCH_SIZE,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        $synced = [];

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            try {
                $intent = $service->readPaymentIntent($order);

                if ($intent === null) {
                    continue;
                }

                // One sync per destination account per run, however many
                // orders are waiting on it.
                $syncKey = $intent->network . ':' . $intent->destinationAccount;
                if (!isset($synced[$syncKey])) {
                    $factory->getSyncService()->syncTransactions($intent->destinationAccount, $intent->network);
                    $synced[$syncKey] = true;
                }

                $fulfilled = $service->syncOrderTransactionWithXrpl($order, false);

                if ($fulfilled instanceof PaymentIntent && $service->isSettled($fulfilled)) {
                    $order->payment_complete((string) $fulfilled->hash);
                }
            } catch (Throwable $exception) {
                $logger->warning('Background settlement failed for an order', [
                    'order_id' => $order->get_id(),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }
}
