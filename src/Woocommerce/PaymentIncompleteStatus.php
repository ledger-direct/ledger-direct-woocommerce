<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Woocommerce;

/**
 * The order status "XRPL payment incomplete".
 *
 * A payment that arrives but does not pay the order (too little, or another
 * token) used to leave the order on "Pending payment", indistinguishable
 * from one nobody has paid. This status says *that* something is wrong in
 * the order list, the status filter and the order's history; the
 * LedgerDirect panel on the order page says *what*. The PrestaShop and
 * Magento plugins have the same state under the same name.
 *
 * It is still an open status: the order needs payment, so the payment page
 * and the status endpoint keep syncing it and the background job keeps
 * matching it, and payment_complete() moves it on once the ledger covers
 * the amount. Being its own status, WooCommerce's hold-stock cancellation
 * (which only cancels "Pending payment") leaves it alone: there is real
 * money on the ledger for it.
 */
final class PaymentIncompleteStatus
{
    /**
     * Without the wc- prefix, as WooCommerce's own status helpers expect it.
     * Short on purpose: WordPress stores it as the post status, a
     * varchar(20) (HPOS has the same width), so with the prefix a status
     * key has 17 characters at most. A longer one is refused by the
     * database and the order silently stays "Pending payment".
     */
    public const STATUS = 'ld-incomplete';

    public static function register(): void
    {
        add_action('init', [self::class, 'registerPostStatus']);
        add_filter('wc_order_statuses', [self::class, 'addToOrderStatuses']);
        add_filter('woocommerce_valid_order_statuses_for_payment', [self::class, 'addToStatusList']);
        add_filter('woocommerce_valid_order_statuses_for_payment_complete', [self::class, 'addToStatusList']);
    }

    public static function label(): string
    {
        return __('XRPL payment incomplete', 'ledger-direct');
    }

    public static function registerPostStatus(): void
    {
        register_post_status('wc-' . self::STATUS, [
            'label' => self::label(),
            'public' => false,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: number of orders */
            'label_count' => _n_noop('XRPL payment incomplete <span class="count">(%s)</span>', 'XRPL payment incomplete <span class="count">(%s)</span>', 'ledger-direct'),
        ]);
    }

    /**
     * Listed right after "Pending payment", which it refines.
     *
     * @param array<string, string> $statuses
     * @return array<string, string>
     */
    public static function addToOrderStatuses(array $statuses): array
    {
        $result = [];

        foreach ($statuses as $key => $label) {
            $result[$key] = $label;

            if ($key === 'wc-pending') {
                $result['wc-' . self::STATUS] = self::label();
            }
        }

        if (!isset($result['wc-' . self::STATUS])) {
            $result['wc-' . self::STATUS] = self::label();
        }

        return $result;
    }

    /**
     * @param string[] $statuses
     * @return string[]
     */
    public static function addToStatusList(array $statuses): array
    {
        if (!in_array(self::STATUS, $statuses, true)) {
            $statuses[] = self::STATUS;
        }

        return $statuses;
    }
}
