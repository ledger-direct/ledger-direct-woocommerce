<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Api;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Service\ServiceFactory;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;
use InvalidArgumentException;
use Throwable;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The payment-status endpoint: "is this order paid?" while its customer
 * watches the payment page.
 *
 * The answer is the core's PaymentStatus payload (INVARIANTS.md, "Payment
 * status") - the same seven fields every LedgerDirect plugin returns - plus
 * the one field only this platform can build: `redirect`, present as soon
 * as the order no longer waits for its payment, whatever ended the wait
 * (settled on the ledger, or cancelled by the merchant, for which the
 * contract has no state).
 *
 * Authentication is the order key in the URL (`wc_order_...`), the same
 * secret that guards WooCommerce's own "order received" page: a guest
 * order is the rule in a crypto checkout and a login would shut it out. A
 * wrong key answers 403 without telling whether the order exists.
 *
 * While the order waits, the ledger is synced (throttled per receiving
 * account, see SyncThrottle) and a hit settles the order right here - the
 * customer need not come back anywhere for the merchant to see "paid".
 */
final class PaymentStatusEndpoint
{
    public const REST_NAMESPACE = 'ledger-direct/v1';

    public const ROUTE = '/payment-status/(?P<order_key>wc_order_[A-Za-z0-9]+)';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoute']);
    }

    public static function registerRoute(): void
    {
        register_rest_route(self::REST_NAMESPACE, self::ROUTE, [
            'methods' => 'GET',
            'callback' => [self::class, 'handle'],
            // Public by design: the order key in the URL is the credential,
            // checked in handle(); see the class comment.
            'permission_callback' => '__return_true',
            'args' => [
                'order_key' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * The URL the payment page polls for this order.
     */
    public static function url(WC_Order $order): string
    {
        return rest_url(self::REST_NAMESPACE . '/payment-status/' . $order->get_order_key());
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $order = self::orderByKey((string) $request->get_param('order_key'));

        if ($order === null) {
            return self::respond(403, ['error' => 'forbidden']);
        }

        $service = ServiceFactory::getInstance()->getOrderTransactionService();
        $logger = ServiceFactory::getInstance()->getLogger();

        try {
            $intent = $service->readPaymentIntent($order);
        } catch (InvalidArgumentException $exception) {
            $logger->error('LedgerDirect: status asked for an order with an unreadable payment record', [
                'order_id' => $order->get_id(),
                'exception' => $exception->getMessage(),
            ]);

            return self::respond(500, ['error' => 'payment_intent_unreadable']);
        }

        if ($intent === null) {
            // The page renders no poll URL for such an order; this only answers a hand-made request.
            return self::respond(404, ['error' => 'no_payment_intent']);
        }

        if ($order->needs_payment()) {
            try {
                $fulfilled = $service->syncOrderTransactionThrottled($order);

                if ($fulfilled !== null) {
                    $intent = $fulfilled;

                    if ($service->isSettled($fulfilled)) {
                        $order->payment_complete((string) $fulfilled->hash);
                    }
                }
            } catch (Throwable $exception) {
                // Answer from what is stored; the next poll or the background job tries again.
                $logger->warning('LedgerDirect: status check could not sync or settle the order', [
                    'order_id' => $order->get_id(),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $payload = $service->paymentStatus($intent)->toArray();

        // Read fresh, not from the object above: a settlement in this very request leaves it stale.
        $order = wc_get_order($order->get_id());

        if ($order instanceof WC_Order && !$order->needs_payment()) {
            $payload['redirect'] = LedgerDirectPaymentGateway::instance()->get_return_url($order);
        }

        return self::respond(200, $payload);
    }

    /**
     * The order the key names, if it is a LedgerDirect order. Null for a
     * wrong key, an unknown key and an order of another gateway alike.
     */
    private static function orderByKey(string $orderKey): ?WC_Order
    {
        $order = wc_get_order(wc_get_order_id_by_order_key($orderKey));

        if (!$order instanceof WC_Order || !hash_equals($order->get_order_key(), $orderKey)) {
            return null;
        }

        if ($order->get_payment_method() !== LedgerDirectPaymentGateway::ID) {
            return null;
        }

        return $order;
    }

    /**
     * A JSON answer no cache may hold on to.
     *
     * @param array<string, mixed> $payload
     */
    private static function respond(int $httpStatus, array $payload): WP_REST_Response
    {
        $response = new WP_REST_Response($payload, $httpStatus);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
