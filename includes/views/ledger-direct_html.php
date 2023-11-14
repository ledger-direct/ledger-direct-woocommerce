<?php

require_once WC_LEDGER_DIRECT_PLUGIN_FILE_PATH . 'vendor/autoload.php';

use Hardcastle\LedgerDirect\Service\ConfigurationService;
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

$container = ld_get_dependency_injection_container();
$orderTransactionService = $container->get(OrderTransactionService::class);
$configurationService = $container->get(ConfigurationService::class);

$xrpl_order_meta = $order->get_meta('xrpl');
$expiry = $xrpl_order_meta['expiry'];

$tx = $orderTransactionService->syncOrderTransactionWithXrpl($order);
if ($tx) {
    // Payment is settled, let's check wether the paid amount is enough
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

    }
}

$network = $configurationService->getNetwork();
$paymentPageTitle = $configurationService->getPaymentPageTitle();
$type = $xrpl_order_meta['type'];

$destinationAccount = $xrpl_order_meta['destination_account'];
$destinationTag = $xrpl_order_meta['destination_tag'];

$price = $order->get_total();
$currencyCode = $order->get_currency();

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

<body>

<div class="ld-container" data-xrp-payment-page="true">
    <div class="ld-content">


        <?php if (!empty($paymentPageTitle)) { ?>
            <div class="ld-header">
                <h1>
                    <?php echo $paymentPageTitle; ?>
                </h1>
            </div>
        <?php } ?>

        <div class="ld-card">

            <div class="ld-card-left">
                <?php if ($type === 'xrp-payment') { ?>
                    <p><?php echo __('sendXrpMessage', 'ledger-direct'); ?></p>
                    <input id="xrp-amount"
                           type="text"
                           name="xrp-amount"
                           value="<?php echo $xrpAmount; ?>"
                           readonly
                           style="display: none;"
                    />
                <?php } elseif ($type === 'token-payment') { ?>
                    <p><?php echo __('sendTokenMessage', 'ledger-direct'); ?></p>
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

                <label for="destinationAccount" class="bootstrap-form-label">
                    <?php echo __('destinationAccountLabel', 'ledger-direct'); ?>
                </label>
                <div class="bootstrap-input-group">
                    <input id="destination-account"
                           type="text"
                           name="destination-account"
                           class="bootstrap-form-control"
                           value="<?php echo $destinationAccount; ?>"
                           readonly
                    />
                    <span class="bootstrap-input-group-text ld-hover" data-bs-toggle="tooltip" data-bs-title="Copy">
                            {% sw_icon 'products' %}
                        </span>
                    <i class="ld-icon">
                        {% sw_icon 'money-wallet' %}
                    </i>
                </div>

                <label for="destinationTag" class="bootstrap-form-label">
                    <?php echo __('destinationTagLabel', 'ledger-direct'); ?>
                </label>
                <div class="bootstrap-input-group">

                    <input id="destination-tag"
                           type="text"
                           name="destination-tag"
                           class="bootstrap-form-control"
                           value="<?php echo $destinationTag; ?>"
                           readonly
                    />
                    <span class="bootstrap-input-group-text ld-hover" data-bs-toggle="tooltip" data-bs-title="Copy">
                            {% sw_icon 'products' %}
                        </span>
                    <i class="ld-icon">
                        {% sw_icon 'tags' %}
                    </i>
                </div>

                <div class="ld-warning">
                    <div role="alert" class="alert alert-warning alert-has-icon">
                        {% sw_icon 'warning' %}
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
                    <div class="ld-sum"><?php echo $price; ?></div>
                    <span><?php echo __('orderNumber', 'ledger-direct'); ?>: <?php echo $order_id; ?></span><br/>
                    <span><?php echo __('price', 'ledger-direct'); ?>: <?php echo $price; ?> <?php echo $currencyCode; ?></span>
                    <br/>
                    <span><?php echo __('price', 'ledger-direct'); ?>: <?php echo $xrpAmount; ?> XRP</span><br/>
                    <span><?php echo __('exchangeRate', 'ledger-direct'); ?>: <?php echo $exchangeRate; ?> XRP / <?php echo $currencyCode; ?></span>
                <?php } elseif ($type === 'token-payment') { ?>
                    <div class="ld-sum">{{ price|currency }}</div>
                    <span><?php echo __('orderNumber', 'ledger-direct'); ?>: <?php echo $order_id; ?></span><br/>
                    <span><?php echo __('price', 'ledger-direct'); ?>: <?php echo $price; ?> <?php echo $currencyCode; ?></span>
                    <br/>
                <?php } ?>
                <img src="<?php echo ld_get_public_url('/public/images/astronaut.png'); ?>" class="ld-astronaut"/>
            </div>

        </div>

        <div class="ld-footer">
            <?php echo $network; ?> - LedgerDirect Payment Plugin
        </div>

    </div>
</div>


<div>
    <h2>DEBUG</h2>
    OrderId: <?php echo $order_id; ?><br/>
    UserId: <?php echo $current_user->ID; ?><br/>
    <pre>
            <?php print_r($order->get_meta('xrpl')); ?>
        </pre>
</div>

<?php wp_footer(); ?>
</body>
</html>
