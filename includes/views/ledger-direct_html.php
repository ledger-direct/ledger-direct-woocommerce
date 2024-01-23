<?php

require_once WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'vendor/autoload.php';

use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use XRPL_PHP\Core\Networks;

$order_id = get_query_var(LedgerDirect::PAYMENT_IDENTIFIER);
$order = wc_get_order($order_id);
$current_user = wp_get_current_user();

if ($current_user->ID !== $order->get_user_id()) {
    // User is not the owner of this order, redirect to shop page
    global $wp_query;
    $shop_page_url = get_permalink( wc_get_page_id( 'shop' ) );
    wp_redirect($shop_page_url);
}

$container = ld_get_dependency_injection_container();
$orderTransactionService = $container->get(OrderTransactionService::class);
$configurationService = $container->get(ConfigurationService::class);

$xrpl_order_meta = $order->get_meta('xrpl');

$tx = $orderTransactionService->syncOrderTransactionWithXrpl($order);
if ($tx) {
    // Payment is settled, let's check if the paid amount is enough
    $xrpl_order_meta = $order->get_meta('xrpl');
    $requestedXrpAmount = (float)$xrpl_order_meta['amount_requested'];
    $paidXrpAmount = (float)$xrpl_order_meta['delivered_amount'];
    $slippage = 0.0015; // TODO: Make this configurable
    $slipped = 1.0 - $paidXrpAmount / $requestedXrpAmount;
    if ($slipped < $slippage) {
        // Payment completed, set transaction status to "paid"
        $order->set_status('completed');
        //wp_redirect($ledgerDirectGateway->get_return_url($order));
        wp_redirect($order->get_checkout_order_received_url()); // http://127.0.0.1/kasse/order-received/27/?key=wc_order_KfJjClCXNDN2o
    } else {
        // Payment amount is not enough, let's wait for more
        $order->set_status('on-hold');
        //wp_redirect($order->get_checkout_payment_url());
        wc_add_notice( 'Payment amount is not enough', 'notice' );
    }
}

if ($orderTransactionService->isExpired($order)) {
    // Quote expired, renew quote
    $xrpl_order_meta = $orderTransactionService->prepareXrplOrderTransaction($order);
    $order->update_meta_data( 'xrpl', $xrpl_order_meta );
    $order->save_meta_data();
    // wc_add_notice( 'Quote has expired', 'notice' );
    // wp_redirect($order->get_checkout_payment_url());
}

$network = $configurationService->getNetwork();
$networkName = Networks::getNetwork($network)['label'];
$paymentPageTitle = $configurationService->getPaymentPageTitle();
$type = $xrpl_order_meta['type'];

$destinationAccount = $xrpl_order_meta['destination_account'];
$destinationTag = $xrpl_order_meta['destination_tag'];

$price = $order->get_total();
$currencyCode = $order->get_currency();
$currencySymbol = get_woocommerce_currency_symbol($currencyCode);

$xrpAmount = $xrpl_order_meta['amount_requested'] ?? -1;
$exchangeRate = $xrpl_order_meta['exchange_rate'] ?? -1;

$tokenAmount = $xrpl_order_meta['amount_requested'] ?? -1;
$issuer = '';
?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>

<body class="ld-body">

<div class="ld-container" data-xrp-payment-page="true">
    <div class="ld-content">


        <?php if (!empty($paymentPageTitle)) { ?>
            <div class="ld-header">
                <h2>
                    <?php echo $paymentPageTitle; ?>
                </h2>
            </div>
        <?php } ?>

        <div class="ld-card">

            <div class="ld-card-left">
                <?php if ($type === 'xrp-payment') { ?>
                    <p><?php echo sprintf(__('sendXrpMessage', 'ledger-direct'), round($xrpAmount, 2)); ?></p>
                    <input id="xrp-amount"
                           type="text"
                           name="xrp-amount"
                           value="<?php echo $xrpAmount; ?>"
                           readonly
                           style="display: none;"
                    />
                <?php } elseif ($type === 'token-payment') { ?>
                    <p><?php echo sprintf(__('sendTokenMessage', 'ledger-direct'), $tokenAmount); ?></p>
                    <input id="token-amount"
                           type="text"
                           name="token-amount"
                           value="<?php echo $tokenAmount; ?>"
                           readonly
                           style="display: none;"
                    />
                    <input id="issuer"
                           type="text"
                           name="token-amount"
                           value="<?php echo $issuer; ?>"
                           readonly
                           style="display: none;"
                    />
                    <input id="currency"
                           type="text"
                           name="currency"
                           value="<?php echo $currencyCode; ?>"
                           readonly
                           style="display: none;"
                    />
                <?php } ?>

                <div class="ld-payment-info">
                    <span>
                        <?php echo __('destinationAccountLabel', 'ledger-direct'); ?>
                        <?php echo ld_get_svg_html('wallet', ['class' => 'inline-svg', 'height' => '16', 'width' => '16', 'viewBox' => '0 0 24 24']); ?>
                    </span>
                    <div class="ld-payment-info-text">
                        <div id="destination-account" class="" data-value="<?php echo $destinationAccount; ?>">
                            <?php echo $destinationAccount; ?>
                        </div>
                        <div class="ld-payment-info-functions">
                            <?php echo ld_get_svg_html('copy', ['class' => 'action-svg']); ?>
                            <?php echo ld_get_svg_html('qr', ['class' => 'action-svg']); ?>
                        </div>
                    </div>
                </div>

                <div class="ld-payment-info">
                    <span>
                        <?php echo __('destinationTagLabel', 'ledger-direct'); ?>
                        <?php echo ld_get_svg_html('tag', ['class' => 'inline-svg', 'height' => '16', 'width' => '16', 'viewBox' => '0 0 24 24']); ?>
                    </span>
                    <div class="ld-payment-info-text">
                        <div id="destination-tag" class="" data-value="<?php echo $destinationTag; ?>">
                            <?php echo $destinationTag; ?>
                        </div>
                        <div class="ld-payment-info-functions">
                            <?php echo ld_get_svg_html('copy', ['class' => 'action-svg']); ?>
                            <?php echo ld_get_svg_html('qr', ['class' => 'action-svg']); ?>
                        </div>
                    </div>
                </div>

                <div class="ld-warning">
                    <div role="alert" class="alert alert-warning alert-has-icon">
                        <div class="alert-content-container">
                            <div class="alert-content">
                                <?php echo __('destinationTagWarning', 'ledger-direct'); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ld-sync">
                    <button id="gem-wallet-button" class="wallet-disabled">G</button>
                    <button id="crossmark-wallet-button" class="wallet-disabled">C</button>
                    <button id="xumm-wallet-button" class="wallet-disabled">X</button>
                    <button id="check-payment-button" data-order-id="<?php echo $order_id; ?>">
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"
                              style="display:none"></span>
                        <?php echo __('checkPaymentButton', 'ledger-direct'); ?>
                    </button>
                </div>

            </div>

            <div class="ld-card-right">
                <?php if ($type === 'xrp-payment') { ?>
                    <div class="ld-sum"><?php echo $price; ?> <?php echo $currencySymbol; ?></div>
                    <span><?php echo __('orderNumber', 'ledger-direct'); ?>: <?php echo $order_id; ?></span><br/>
                    <span><?php echo __('price', 'ledger-direct'); ?>: <?php echo $price; ?> <?php echo $currencyCode; ?></span><br/>
                    <span><?php echo __('price', 'ledger-direct'); ?>: <?php echo $xrpAmount; ?> XRP</span><br/>
                    <span><?php echo __('exchangeRate', 'ledger-direct'); ?>: <?php echo $exchangeRate; ?> XRP / <?php echo $currencyCode; ?></span><br/>
                    <span><?php echo __('Network:', 'ledger-direct'); ?>: <?php echo $networkName; ?></span><br/>
                <?php } elseif ($type === 'token-payment') { ?>
                    <div class="ld-sum">{{ price|currency }}</div>
                    <span><?php echo __('orderNumber', 'ledger-direct'); ?>: <?php echo $order_id; ?></span><br/>
                    <span><?php echo __('price', 'ledger-direct'); ?>: <?php echo $price; ?> <?php echo $currencyCode; ?></span><br/>
                    <span><?php echo __('Network:', 'ledger-direct'); ?>: <?php echo $networkName; ?></span><br/>
                <?php } ?>
                <img src="<?php echo ld_get_public_url('/public/images/astronaut.png'); ?>" class="ld-astronaut" alt=""/>
            </div>

        </div>

        <div class="ld-footer">
            <a href="<?php echo $order->get_checkout_payment_url(); ?>" class="ld-back-to-cart">
                <?php echo __('backButton', 'ledger-direct'); ?>
            </a>
        </div>

    </div>
</div>

<div style="display: none;">
    OrderId: <?php echo $order_id; ?><br/>
    UserId: <?php echo $current_user->ID; ?><br/>
    <pre>
        <?php print_r($order->get_meta('xrpl')); ?>
    </pre>
</div>

<?php wp_footer(); ?>
</body>
</html>
