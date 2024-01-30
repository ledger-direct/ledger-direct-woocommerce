<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Woocommerce;

use Exception;

class LedgerDirectXrplTokenPaymentGateway extends LedgerDirectPaymentGateway
{
    public static ?LedgerDirectPaymentGateway $_instance = null;
    public static function instance(): self
    {
        if (self::$_instance == null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function __construct()
    {
        $this->id = self::TOKEN_PAYMENT_ID;
        $this->method_title = __("Accept Token payments", "ledger-direct");
        $this->method_description = __("Receive Token payments into your XRPL account", "ledger-direct");

        $this->title = 'XRPL Token Payment | Ledger Direct'; // $this->get_option('title') ?? 'XRP Payment | Ledger Direct';
        $this->description = 'Pay with Token'; // $this->get_option('description') ?? 'Pay with Token';

        parent::__construct();
    }

    /**
     * Process the payment and return the result.
     *
     * @param $order_id
     * @return array
     * @throws Exception
     */
    public function process_payment($order_id): array
    {
        global $wp_query;

        $order = wc_get_order( $order_id );

        if (empty($order))
        {
            $wp_query->set_404();
            status_header( 404 );
            get_template_part('404');
            exit();
        }

        $xrpl_order_meta = $order->get_meta('xrpl');
        $tx = $this->orderTransactionService->syncOrderTransactionWithXrpl($order);
        if ($this->orderTransactionService->checkPayment($order)) {
            // Payment is settled, let's check wether the paid amount is enough
            $requestedXrpAmount = (float) $xrpl_order_meta['amount_requested'];
            $paidXrpAmount = (float) $xrpl_order_meta['delivered_amount'];
            $slippage = 0.0015; // TODO: Make this configurable
            $slipped = 1.0 - $paidXrpAmount / $requestedXrpAmount;
            if($slipped < $slippage) {
                // Payment completed, set transaction status to "paid"
                $order->set_status('completed');

                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url($order),
                );
            } else {
                // Payment incomplete, something's wrong with the amount delivered.
            }
        }

        $ledgerDirectControllerUrl = get_site_url(). '/ledger-direct/payment/' . $order_id;

        // Remove cart.
        // WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $ledgerDirectControllerUrl,
        );
    }
}