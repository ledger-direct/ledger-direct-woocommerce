<?php
    $order_id = get_query_var(LedgerDirect::PAYMENT_IDENTIFIER);
    $order = wc_get_order($order_id);
    $current_user = wp_get_current_user();

    if ($current_user->ID !== $order->get_user_id()) {
        global $wp_query;
        $wp_query->set_404();
        status_header( 404 );
        get_template_part('404');
        exit();
    }

    require_once WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'src/Service/OrderTransactionService.php';

    $orderTransactionService = \Hardcastle\LedgerDirect\Service\OrderTransactionService::instance();

    $tx = $orderTransactionService->syncOrderTransactionWithXrpl($order);
    if ($orderTransactionService->checkPayment($order)) {
        //wp_redirect($ledgerDirectGateway->get_return_url($order));
        wp_redirect($order->get_checkout_order_received_url());
    }

?>

<h1>Ledger Direct Payment Page [WIP]</h1>

<button onclick="location.reload();">
    Check Payment
</button>

<div>
    <h2>DEBUG</h2>
    OrderId: <?php echo $order_id; ?><br />
    UserId: <?php echo $current_user->ID; ?><br />
    <pre>
        <?php print_r($order->get_meta('xrpl')); ?>
    </pre>
</div>