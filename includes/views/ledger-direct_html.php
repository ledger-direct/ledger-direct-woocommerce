<?php
require_once WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'vendor/autoload.php';

use DI\Container;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;

$order_id = get_query_var(LedgerDirect::PAYMENT_IDENTIFIER);
$order = wc_get_order($order_id);
$current_user = wp_get_current_user();

if ($current_user->ID !== $order->get_user_id()) {
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    get_template_part('404');
    exit();
}

$container = new Container([
    CryptoPriceProviderInterface::class => \DI\autowire(XrpPriceProvider::class),
]);
$orderTransactionService = $container->get(OrderTransactionService::class);

$tx = $orderTransactionService->syncOrderTransactionWithXrpl($order);
if ($tx) {
    // Payment is settled, let's check wether the paid amount is enough
    $xrpl_order_meta = $order->get_meta('xrpl');
    $requestedXrpAmount = (float) $xrpl_order_meta['amount_requested'];
    $paidXrpAmount = (float) $xrpl_order_meta['delivered_amount'];
    $slippage = 0.0015; // TODO: Make this configurable
    $slipped = 1.0 - $paidXrpAmount / $requestedXrpAmount;
    if($slipped < $slippage) {
        // Payment completed, set transaction status to "paid"
        $order->set_status('completed');
        //wp_redirect($ledgerDirectGateway->get_return_url($order));
        wp_redirect($order->get_checkout_order_received_url()); // http://127.0.0.1/kasse/order-received/27/?key=wc_order_KfJjClCXNDN2o
    } else {

    }
}

?>

<h1>Ledger Direct Payment Page [WIP]</h1>

<button onclick="location.reload();">
    Check Payment
</button>

<div>
    <h2>DEBUG</h2>
    OrderId: <?php echo $order_id; ?><br/>
    UserId: <?php echo $current_user->ID; ?><br/>
    <pre>
        <?php print_r($order->get_meta('xrpl')); ?>
    </pre>
</div>