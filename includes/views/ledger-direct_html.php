<?php

defined( 'ABSPATH' ) || exit;

require_once LEDGER_DIRECT_PLUGIN_FILE_PATH . 'vendor/autoload.php';

use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;
use Hardcastle\XRPL_PHP\Core\Networks;

global $ledger_direct_order;

$current_user = wp_get_current_user();

// Check if user is owner of the order, otherwise redirect to shop page
if ($current_user->ID !== $ledger_direct_order->get_user_id()) {
    global $wp_query;
    $shop_page_url = get_permalink( wc_get_page_id( 'shop' ) );
    wp_redirect($shop_page_url);
}

?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>

<body class="ld-body">

<?php

if (!$ledger_direct_order || !is_a($ledger_direct_order, 'WC_Order')) {
    echo '<div class="woocommerce-error">';
    echo '<h2>' . esc_html__('Order not found', 'ledger-direct') . '</h2>';
    echo '<p>' . esc_html__('The requested order could not be found.', 'ledger-direct') . '</p>';
    echo '<a href="' . esc_url(home_url()) . '" class="button">' . esc_html__('Return to homepage', 'ledger-direct') . '</a>';
    echo '</div>';
    return;
}

/*
if (!$ledger_direct_order->get_meta('_ledger_direct_order', true)) {
    echo '<div class="woocommerce-error">';
    echo '<h2>' . __('Invalid Order', 'ledger-direct') . '</h2>';
    echo '<p>' . __('This order is not a valid Ledger Direct order.', 'ledger-direct') . '</p>';
    echo '<a href="' . home_url() . '" class="button">' . __('Return to homepage', 'ledger-direct') . '</a>';
    echo '</div>';
    return;
}
*/

$order_id = $ledger_direct_order->get_id();
$order_key = $ledger_direct_order->get_order_key();
$order_status = $ledger_direct_order->get_status();

$valid_statuses = ['pending', 'on-hold', 'processing'];
if (!in_array($order_status, $valid_statuses)) {
    $order_status_info_text = sprintf(__('Order #%d is already %s.', 'ledger-direct'), $order_id, wc_get_order_status_name($order_status)) ;
    echo '<div class="woocommerce-info">';
    echo '<h2>' . esc_html__('Payment not required', 'ledger-direct') . '</h2>';
    echo '<p>' . esc_html($order_status_info_text) . '</p>';
    echo '<a href="' . esc_url($ledger_direct_order->get_view_order_url()) . '" class="button">' . esc_html__('View Order', 'ledger-direct') . '</a>';
    echo '</div>';
    return;
}

$payment_method = $ledger_direct_order->get_payment_method();
if ($payment_method !== LedgerDirectPaymentGateway::ID) {
    echo '<div class="woocommerce-error">';
    echo '<h2>' . esc_html__('Invalid payment method', 'ledger-direct') . '</h2>';
    echo '<p>' . esc_html__('This order was not paid with LedgerDirect.', 'ledger-direct') . '</p>';
    echo '<a href="' . esc_url(home_url()) . '" class="button">' . esc_html__('Return to homepage', 'ledger-direct') . '</a>';
    echo '</div>';
    return;
}

$container = ld_get_dependency_injection_container();
$order_transaction_service = $container->get(OrderTransactionService::class);
$configuration_service = $container->get(ConfigurationService::class);

$payment_page_title = $configuration_service->getPaymentPageTitle();
$meta = $ledger_direct_order->get_meta(LedgerDirect::META_KEY, true);
$network = $meta['network'] ?: 'mainnet';
$network_name = Networks::getNetwork($network)['label'];
$payment_type = $meta['type'] ?: 'xrp';

$supported_payment_types = [
    LedgerDirectPaymentGateway::XRP_PAYMENT_ID,
    LedgerDirectPaymentGateway::TOKEN_PAYMENT_ID,
    LedgerDirectPaymentGateway::RLUSD_PAYMENT_ID
];
if (!in_array($payment_type, $supported_payment_types)) {
    echo '<div class="woocommerce-error">';
    echo '<h2>' . esc_html__('Invalid Payment Method', 'ledger-direct') . '</h2>';
    echo '<p>' . esc_html__('This order was not paid with Ledger Direct.', 'ledger-direct') . '</p>';
    echo '<a href="' . esc_url(home_url()) . '" class="button">' . esc_html__('Return to homepage', 'ledger-direct') . '</a>';
    echo '</div>';
    return;
}


$chain = $meta['chain'];
$destination_account = $meta['destination_account'];
$destination_tag = $meta['destination_tag'];

$total = $ledger_direct_order->get_total();
$wp_currency = $ledger_direct_order->get_currency();
$currency_symbol = get_woocommerce_currency_symbol($wp_currency);

// XRP specific data
$amount_requested = $meta['amount_requested'] ?? -1;
$exchange_rate = $meta['exchange_rate'] ?? -1;

// Custom token and stablecoin specific data
$token_amount = $meta['token_amount'] ?? -1;
$issuer = $meta['issuer'] ?? -1;
$currency_code = $meta['currency_code'] ?? get_woocommerce_currency();
$pairing = $meta['pairing'] ?? -1;

$allowed_svg_html = [
        'svg'   => [
                'xmlns' => true,
                'xmlns:xlink' => true,
                'id' => true,
                'class' => true,
                'width' => true,
                'height' => true,
                'viewbox' => true,
        ],
        'defs'  => [],
        'path'  => [
                'id' => true,
                'd' => true,
        ],
        'use'   => [
                'xlink:href' => true,
        ],
];
$wallet_icon_svg = ld_get_svg_html('wallet', ['class' => 'inline-svg', 'height' => '16', 'width' => '16', 'viewBox' => '0 0 24 24']);
$tag_icon_svg = ld_get_svg_html('tag', ['class' => 'inline-svg', 'height' => '16', 'width' => '16', 'viewBox' => '0 0 24 24']);
$copy_icon_svg = ld_get_svg_html('copy', ['class' => 'action-svg']);
$qr_icon_svg = ld_get_svg_html('qr', ['class' => 'action-svg']);

?>

<div class="ld-header">
    <h3>
        <?php $page_title = 'LedgerDirect - pay with ' . strtoupper($payment_type) . ' directly on ' . $chain; ?>
        <?php echo esc_html($page_title); ?>
    </h3>
</div>

<div class="ld-container" data-xrp-payment-page="true">
    <div class="ld-content">

        <div class="ld-card">

            <div class="ld-card-left">
                <?php if ($payment_type === LedgerDirectPaymentGateway::XRP_PAYMENT_ID) { ?>
                    <?php $instructions = sprintf(__('Please send <strong>%s</strong> XRP to the following address:', 'ledger-direct'), $amount_requested); ?>
                    <p><?php echo esc_html($instructions); ?></p>
                    <input id="xrp-amount"
                           type="text"
                           name="xrp-amount"
                           value="<?php echo esc_attr($amount_requested); ?>"
                           readonly
                           style="display: none;"
                    />
                <?php } elseif ($payment_type === LedgerDirectPaymentGateway::RLUSD_PAYMENT_ID) { ?>
                    <?php $instructions = sprintf(__('Please send <strong>%s</strong> <strong>%s</strong> to the following address:', 'ledger-direct'), $amount_requested['value'], 'RLUSD'); ?>
                    <p><?php echo esc_html($instructions); ?></p>
                    <input id="rlusd-amount"
                           type="text"
                           name="rlusd-amount"
                           value="<?php echo esc_attr($amount_requested); ?>"
                           readonly
                           style="display: none;"
                    />
                    <input id="pairing"
                           type="text"
                           name="pairing"
                           value="<?php echo esc_attr($pairing); ?>"
                           readonly
                           style="display: none;"
                    />
                <?php } elseif ($payment_type === LedgerDirectPaymentGateway::TOKEN_PAYMENT_ID) { ?>
                    <?php $instructions = sprintf(__('Please send <strong>%s</strong> <strong>%s</strong> to the following address:', 'ledger-direct'), $token_amount, $wp_currency); ?>
                    <p><?php echo esc_html($instructions); ?></p>
                    <input id="token-amount"
                           type="text"
                           name="token-amount"
                           value="<?php echo esc_attr($token_amount); ?>"
                           readonly
                           style="display: none;"
                    />
                    <input id="issuer"
                           type="text"
                           name="token-amount"
                           value="<?php echo esc_attr($issuer); ?>"
                           readonly
                           style="display: none;"
                    />
                    <input id="currency"
                           type="text"
                           name="currency"
                           value="<?php echo esc_attr($currency_code); ?>"
                           readonly
                           style="display: none;"
                    />
                <?php } ?>

                <div class="ld-payment-info">
                    <span>
                        <?php esc_html_e('Account', 'ledger-direct'); ?>
                        <?php echo wp_kses($wallet_icon_svg, $allowed_svg_html); ?>
                    </span>
                    <div class="ld-payment-info-text">
                        <div id="destination-account" class="" data-value="<?php echo esc_attr($destination_account); ?>">
                            <?php echo esc_html($destination_account); ?>
                        </div>
                        <div class="ld-payment-info-functions">
                            <?php echo wp_kses($copy_icon_svg, $allowed_svg_html); ?>
                            <?php echo wp_kses($qr_icon_svg, $allowed_svg_html); ?>
                        </div>
                    </div>
                </div>

                <div class="ld-payment-info">
                    <span>
                        <?php esc_html_e('Destination Tag', 'ledger-direct'); ?>
                        <?php echo wp_kses($tag_icon_svg, $allowed_svg_html); ?>
                    </span>
                    <div class="ld-payment-info-text">
                        <div id="destination-tag" class="" data-value="<?php echo esc_attr($destination_tag); ?>">
                            <?php echo esc_html($destination_tag); ?>
                        </div>
                        <div class="ld-payment-info-functions">
                            <?php echo wp_kses($copy_icon_svg, $allowed_svg_html); ?>
                            <?php echo wp_kses($qr_icon_svg, $allowed_svg_html); ?>
                        </div>
                    </div>
                </div>

                <div class="ld-warning">
                    <div role="alert" class="alert alert-warning alert-has-icon">
                        <div class="alert-content-container">
                            <div class="alert-content">
                                <?php esc_html_e('It is important to include the given destination tag when sending funds from your wallet.', 'ledger-direct'); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ld-sync">
                    <!-- Wallet buttons -->
                    <!--
                    <button id="gem-wallet-button" class="wallet-disabled">G</button>
                    <button id="crossmark-wallet-button" class="wallet-disabled">C</button>
                    <button id="xumm-wallet-button" class="wallet-disabled">X</button>
                    -->
                    <button id="check-payment-button" data-order-id="<?php echo esc_attr($order_id); ?>">
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" style="display:none"></span>
                        <?php esc_html_e('Check payment processing', 'ledger-direct'); ?>
                    </button>
                </div>

            </div>

            <div class="ld-card-right">
                <?php if ($payment_type === LedgerDirectPaymentGateway::XRP_PAYMENT_ID) { ?>
                    <div class="ld-sum"><?php echo esc_html($total); ?><?php echo esc_html($currency_symbol); ?></div>
                    <span><?php esc_html_e('Order ID', 'ledger-direct'); ?>: <?php echo esc_html($order_id); ?></span><br/>
                    <span><?php esc_html_e('Total', 'ledger-direct'); ?>: <?php echo esc_html($total); ?> <?php echo esc_html($wp_currency); ?></span>
                    <br/>
                    <span><?php esc_html_e('Total', 'ledger-direct'); ?>: <?php echo esc_html($amount_requested); ?> XRP</span><br/>
                    <span><?php esc_html_e('Exchange rate', 'ledger-direct'); ?>: <?php echo esc_html($exchange_rate); ?> XRP / <?php echo esc_html($currency_code); ?></span>
                    <br/>
                    <span><?php esc_html_e('Network', 'ledger-direct'); ?>: <?php echo esc_html($network_name); ?></span><br/>
                <?php } elseif ($payment_type === LedgerDirectPaymentGateway::RLUSD_PAYMENT_ID) { ?>
                    <div class="ld-sum"><?php echo esc_html($total); ?><?php echo esc_html($currency_symbol); ?></div>
                    <span><?php esc_html_e('Order ID', 'ledger-direct'); ?>: <?php echo esc_html($order_id); ?></span><br/>
                    <span><?php esc_html_e('Total', 'ledger-direct'); ?>: <?php echo esc_html($total); ?> <?php echo esc_html($wp_currency); ?></span>
                    <br/>
                    <span><?php esc_html_e('Total', 'ledger-direct'); ?>: <?php echo esc_html($amount_requested['value']); ?> RLUSD</span><br/>
                    <span><?php esc_html_e('Exchange rate', 'ledger-direct'); ?>: <?php echo esc_html($exchange_rate); ?> <?php echo esc_html($pairing); ?></span>
                    <br/>
                    <span><?php esc_html_e('Network', 'ledger-direct'); ?>: <?php echo esc_html($network_name); ?></span><br/>
                <?php } elseif ($payment_type === LedgerDirectPaymentGateway::TOKEN_PAYMENT_ID) { ?>
                    <div class="ld-sum"><?php echo esc_html($token_amount); ?> | <?php echo esc_html($currency_code); ?></div>
                    <span><?php esc_html_e('Order ID', 'ledger-direct'); ?>: <?php echo esc_html($order_id); ?></span><br/>
                    <span><?php esc_html_e('Total', 'ledger-direct'); ?>: <?php echo esc_html($token_amount); ?> <?php echo esc_html($currency_code); ?></span>
                    <br/>
                    <span><?php esc_html_e('Network', 'ledger-direct'); ?>: <?php echo esc_html($network_name); ?></span><br/>
                <?php } ?>
                <img src="<?php echo esc_url(ld_get_public_url('/public/images/astronaut.png')); ?>" class="ld-astronaut" alt=""/>
            </div>

        </div>

        <div class="ld-footer">
            <a href="<?php echo esc_url($ledger_direct_order->get_checkout_payment_url()); ?>" class="ld-back-to-cart">
                <?php esc_html_e('Back', 'ledger-direct'); ?>
            </a>
        </div>

    </div>
</div>

<?php wp_footer(); ?>
</body>
</html>