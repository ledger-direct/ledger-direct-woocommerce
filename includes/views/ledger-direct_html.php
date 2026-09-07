<?php declare(strict_types=1);

defined( 'ABSPATH' ) || exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;

/**
 * @var WC_Order|null $ledger_direct_order
 * @var object|null $ledger_direct_intent  A core PaymentIntent (this file is not scoped, so the class is not referenced)
 * @var string|null $ledger_direct_shortfall
 */
global $ledger_direct_order, $ledger_direct_intent, $ledger_direct_shortfall;

$ledger_direct_current_user = wp_get_current_user();

// Check if user is owner of the order, otherwise redirect to shop page
if ($ledger_direct_order && $ledger_direct_current_user->ID !== $ledger_direct_order->get_user_id()) {
    wp_safe_redirect(get_permalink( wc_get_page_id( 'shop' ) ));
    exit;
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

$order_id = $ledger_direct_order->get_id();
$order_status = $ledger_direct_order->get_status();

$valid_statuses = ['pending', 'on-hold', 'processing'];
if (!in_array($order_status, $valid_statuses, true)) {
    /* translators: 1: order number, 2: order status label */
    $order_status_info_text = sprintf(__('Order #%1$d is already %2$s.', 'ledger-direct'), $order_id, wc_get_order_status_name($order_status));
    echo '<div class="woocommerce-info">';
    echo '<h2>' . esc_html__('Payment not required', 'ledger-direct') . '</h2>';
    echo '<p>' . esc_html($order_status_info_text) . '</p>';
    echo '<a href="' . esc_url($ledger_direct_order->get_view_order_url()) . '" class="button">' . esc_html__('View Order', 'ledger-direct') . '</a>';
    echo '</div>';
    return;
}

$payment_method = $ledger_direct_order->get_payment_method();
if ($payment_method !== LedgerDirectPaymentGateway::ID || !is_object($ledger_direct_intent)) {
    echo '<div class="woocommerce-error">';
    echo '<h2>' . esc_html__('Invalid payment method', 'ledger-direct') . '</h2>';
    echo '<p>' . esc_html__('This order was not paid with LedgerDirect.', 'ledger-direct') . '</p>';
    echo '<a href="' . esc_url(home_url()) . '" class="button">' . esc_html__('Return to homepage', 'ledger-direct') . '</a>';
    echo '</div>';
    return;
}

$intent = $ledger_direct_intent;

$base_asset = $intent->baseAsset;
$network_name = $intent->network === 'mainnet' ? 'Mainnet' : 'Testnet';
$destination_account = $intent->destinationAccount;
$destination_tag = $intent->destinationTag;

$total = $ledger_direct_order->get_total();
$wp_currency = $ledger_direct_order->get_currency();
$currency_symbol = get_woocommerce_currency_symbol($wp_currency);

// The amount the customer is asked to send, as a plain number, and the
// issued-currency envelope for the stablecoins (what a wallet needs).
$amount_requested = $intent->amountRequestedValue();
$exchange_rate = $intent->exchangeRate;
$pairing = $intent->pairing;
$issued_amount = is_array($intent->amountRequested) ? $intent->amountRequested : null;

$amount_paid = $intent->amountPaidValue();
$shortfall = $ledger_direct_shortfall;
// A same-named token from another issuer is a different asset and never settles.
$paid_wrong_asset = is_array($intent->amountPaid) && is_array($intent->amountRequested)
    && ($intent->amountPaid['issuer'] !== $intent->amountRequested['issuer']
        || $intent->amountPaid['currency'] !== $intent->amountRequested['currency']);
$expires_in = $intent->expiry !== null ? max(0, $intent->expiry - time()) : null;

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
$wallet_icon_svg = ledger_direct_get_svg_html('wallet', ['class' => 'inline-svg', 'height' => '16', 'width' => '16', 'viewBox' => '0 0 24 24']);
$tag_icon_svg = ledger_direct_get_svg_html('tag', ['class' => 'inline-svg', 'height' => '16', 'width' => '16', 'viewBox' => '0 0 24 24']);
$copy_icon_svg = ledger_direct_get_svg_html('copy', ['class' => 'action-svg']);
$qr_icon_svg = ledger_direct_get_svg_html('qr', ['class' => 'action-svg']);

?>

<div class="ld-header">
    <h3>
        <?php $page_title = 'LedgerDirect - pay with ' . $base_asset . ' directly on ' . $network_name; ?>
        <?php echo esc_html($page_title); ?>
    </h3>
</div>

<div class="ld-container" data-xrp-payment-page="true">
    <div class="ld-content">

        <div class="ld-card">

            <div class="ld-card-left">
                <?php if ($base_asset === 'XRP') { ?>
                    <?php /* translators: %s: XRP amount to send */ ?>
                    <?php $instructions = sprintf(__('Please send %s XRP to the following address:', 'ledger-direct'), $amount_requested); ?>
                    <p><?php echo esc_html($instructions); ?></p>
                    <input id="xrp-amount"
                           type="text"
                           name="xrp-amount"
                           value="<?php echo esc_attr($amount_requested); ?>"
                           readonly
                           style="display: none;"
                    />
                <?php } else { ?>
                    <?php /* translators: 1: token amount, 2: token symbol (RLUSD, USDC) */ ?>
                    <?php $instructions = sprintf(__('Please send %1$s %2$s to the following address:', 'ledger-direct'), $amount_requested, $base_asset); ?>
                    <p><?php echo esc_html($instructions); ?></p>
                    <input id="token-amount"
                           type="text"
                           name="token-amount"
                           value="<?php echo esc_attr($amount_requested); ?>"
                           readonly
                           style="display: none;"
                    />
                    <input id="issuer"
                           type="text"
                           name="issuer"
                           value="<?php echo esc_attr((string) ($issued_amount['issuer'] ?? '')); ?>"
                           readonly
                           style="display: none;"
                    />
                    <input id="currency"
                           type="text"
                           name="currency"
                           value="<?php echo esc_attr((string) ($issued_amount['currency'] ?? '')); ?>"
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
                        <div id="destination-tag" class="" data-value="<?php echo esc_attr((string) $destination_tag); ?>">
                            <?php echo esc_html((string) $destination_tag); ?>
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

                <?php if ($amount_paid !== null && $shortfall !== null) { ?>
                    <div class="ld-warning">
                        <div role="alert" class="alert alert-warning alert-has-icon">
                            <div class="alert-content-container">
                                <div class="alert-content">
                                    <?php if ($paid_wrong_asset) { ?>
                                        <?php /* translators: %s: token symbol (RLUSD, USDC) */ ?>
                                        <?php echo esc_html(sprintf(__('A payment arrived, but not in the requested %s. Please send the amount below in the requested token.', 'ledger-direct'), $base_asset)); ?>
                                    <?php } else { ?>
                                        <?php /* translators: 1: amount received, 2: amount requested, 3: amount still missing, 4: asset symbol */ ?>
                                        <?php echo esc_html(sprintf(__('Received %1$s of %2$s %4$s so far. Please send the remaining %3$s %4$s.', 'ledger-direct'), $amount_paid, $amount_requested, $shortfall, $base_asset)); ?>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php } ?>

                <div class="ld-sync">
                    <button id="check-payment-button" data-order-id="<?php echo esc_attr((string) $order_id); ?>">
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" style="display:none"></span>
                        <?php esc_html_e('Check payment processing', 'ledger-direct'); ?>
                    </button>
                </div>

            </div>

            <div class="ld-card-right">
                <div class="ld-sum"><?php echo esc_html($total); ?><?php echo esc_html($currency_symbol); ?></div>
                <span><?php esc_html_e('Order ID', 'ledger-direct'); ?>: <?php echo esc_html((string) $order_id); ?></span><br/>
                <span><?php esc_html_e('Total', 'ledger-direct'); ?>: <?php echo esc_html($total); ?> <?php echo esc_html($wp_currency); ?></span>
                <br/>
                <span><?php esc_html_e('Total', 'ledger-direct'); ?>: <?php echo esc_html($amount_requested); ?> <?php echo esc_html($base_asset); ?></span><br/>
                <span><?php esc_html_e('Exchange rate', 'ledger-direct'); ?>: <?php echo esc_html((string) $exchange_rate); ?> <?php echo esc_html($pairing); ?></span>
                <br/>
                <span><?php esc_html_e('Network', 'ledger-direct'); ?>: <?php echo esc_html($network_name); ?></span><br/>
                <?php if ($expires_in !== null && $amount_paid === null) { ?>
                    <?php /* translators: %d: minutes */ ?>
                    <span><?php echo esc_html(sprintf(__('Quote valid for %d more minutes', 'ledger-direct'), (int) ceil($expires_in / 60))); ?></span><br/>
                <?php } ?>
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
