<?php declare(strict_types=1);

defined( 'ABSPATH' ) || exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;

/**
 * @var WC_Order|null $ledger_direct_order
 * @var object|null $ledger_direct_intent  A core PaymentIntent (this file is not scoped, so the class is not referenced)
 * @var object|null $ledger_direct_status  A core PaymentStatus, same reason
 * @var string|null $ledger_direct_shortfall
 * @var string|null $ledger_direct_poll_url
 * @var string|null $ledger_direct_refresh_nonce
 */
global $ledger_direct_order, $ledger_direct_intent, $ledger_direct_status, $ledger_direct_shortfall, $ledger_direct_poll_url, $ledger_direct_refresh_nonce;

/*
 * Whoever holds the order key holds the page - the same rule as WooCommerce's
 * own "order received" page, and the only one that works for the guest order
 * a crypto checkout mostly is, or for a customer coming back from the
 * confirmation mail in another browser.
 */

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

/*
 * The five-state payment status (INVARIANTS.md, "Payment status"), rendered
 * server-side: one block per state, the server decides which starts visible,
 * the script only switches them and fills in two numbers from the poll. Which
 * state it is - partial, wrong asset, expired - is the core's decision, not a
 * comparison made here. Every amount is the plain decimal the core states;
 * nothing is rounded. A settled order never renders this page.
 */
$state = is_object($ledger_direct_status) ? $ledger_direct_status->state : 'waiting';
$seconds_left = is_object($ledger_direct_status) ? $ledger_direct_status->secondsLeft : null;
$has_expiry = $intent->expiry !== null;
$amount_paid = (string) $intent->amountPaidValue();
$shortfall = (string) $ledger_direct_shortfall;
$countdown = $seconds_left === null ? '' : sprintf('%d:%02d', intdiv(max(0, $seconds_left), 60), max(0, $seconds_left) % 60);
$page_url = LedgerDirectPaymentGateway::get_payment_page_url($ledger_direct_order);
$order_key = $ledger_direct_order->get_order_key();
$allowed_amount_html = [
        'strong' => [
                'data-ld-paid' => true,
                'data-ld-shortfall' => true,
        ],
];

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

<div class="ld-container"
     data-xrp-payment-page="true"
     data-ld-state="<?php echo esc_attr($state); ?>"
     data-ld-poll-url="<?php echo esc_url($ledger_direct_poll_url ?? ''); ?>"
     <?php if ($seconds_left !== null) { ?>data-ld-seconds-left="<?php echo esc_attr((string) $seconds_left); ?>"<?php } ?>>
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

                <?php
                /*
                 * Something arrived but the order is not paid. Neutral tone on
                 * purpose: the customer did what their wallet told them; the page
                 * explains, it does not alarm. The markup enclosing the numbers is
                 * passed in as the placeholders, so the translations carry none of
                 * it and the script can find the numbers to update.
                 */
                ?>
                <div class="ld-warning" data-ld-partial <?php echo $state !== 'partial' ? 'hidden' : ''; ?>>
                    <div role="alert" class="alert alert-info">
                        <div class="alert-content-container">
                            <div class="alert-content">
                                <?php
                                $partial_notice = sprintf(
                                    /* translators: 1: amount received so far with asset symbol, 2: amount still outstanding with asset symbol */
                                    __('%1$s received so far. %2$s is still outstanding – please send the remaining amount to the same address, with the same destination tag.', 'ledger-direct'),
                                    '<strong data-ld-paid>' . esc_html($amount_paid) . '</strong> ' . esc_html($base_asset),
                                    '<strong data-ld-shortfall>' . esc_html($shortfall) . '</strong> ' . esc_html($base_asset)
                                );
                                echo wp_kses($partial_notice, $allowed_amount_html);
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ld-warning" data-ld-wrong-asset <?php echo $state !== 'wrong_asset' ? 'hidden' : ''; ?>>
                    <div role="alert" class="alert alert-info">
                        <div class="alert-content-container">
                            <div class="alert-content">
                                <?php
                                $wrong_asset_notice = sprintf(
                                    /* translators: 1: amount that arrived, 2: amount still outstanding, 3: token symbol (RLUSD, USDC) */
                                    __('A payment of %1$s arrived, but not in the token this order is quoted in – the currency or the issuer does not match, so it cannot be credited. Please send %2$s %3$s from the issuer shown above, or contact us about the payment you already made.', 'ledger-direct'),
                                    '<strong data-ld-paid>' . esc_html($amount_paid) . '</strong>',
                                    '<strong data-ld-shortfall>' . esc_html($shortfall) . '</strong>',
                                    esc_html($base_asset)
                                );
                                echo wp_kses($wrong_asset_notice, $allowed_amount_html);
                                ?>
                            </div>
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

                <?php if ($has_expiry) { ?>
                    <?php
                    /*
                     * Both blocks are always rendered so the countdown can swap them
                     * without a reload; the server decides which one starts visible.
                     * Neither shows while a payment has arrived: the state blocks
                     * above take over, and a refresh would re-quote an order that is
                     * already partly paid.
                     */
                    ?>
                    <p class="ld-quote-validity" data-ld-live <?php echo $state !== 'waiting' ? 'hidden' : ''; ?>>
                        <?php esc_html_e('This amount is guaranteed for', 'ledger-direct'); ?>
                        <strong data-ld-countdown><?php echo esc_html($countdown); ?></strong>
                    </p>

                    <div class="ld-warning" data-ld-expired <?php echo $state !== 'expired' ? 'hidden' : ''; ?>>
                        <div role="alert" class="alert alert-warning">
                            <div class="alert-content-container">
                                <div class="alert-content">
                                    <p><?php esc_html_e('This quote has expired. The exchange rate may have moved since.', 'ledger-direct'); ?></p>
                                    <p><?php esc_html_e('Already sent the old amount? Do not send it again – use the check button below instead.', 'ledger-direct'); ?></p>
                                    <form method="post" action="<?php echo esc_url($page_url); ?>">
                                        <input type="hidden" name="<?php echo esc_attr(LedgerDirect::ORDER_IDENTIFIER); ?>" value="<?php echo esc_attr($order_key); ?>">
                                        <input type="hidden" name="ledger_direct_refresh" value="1">
                                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr((string) $ledger_direct_refresh_nonce); ?>">
                                        <button type="submit" class="ld-refresh-quote">
                                            <?php esc_html_e('Get an updated amount', 'ledger-direct'); ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php } ?>

                <div class="ld-sync">
                    <?php
                    /*
                     * The manual path, and the only one a browser without JavaScript
                     * has: a plain reload of the page, which syncs and settles. The
                     * script turns the click into the same request the poll makes.
                     */
                    ?>
                    <form method="get" action="<?php echo esc_url($page_url); ?>">
                        <input type="hidden" name="<?php echo esc_attr(LedgerDirect::ORDER_IDENTIFIER); ?>" value="<?php echo esc_attr($order_key); ?>">
                        <button type="submit" id="check-payment-button" data-order-id="<?php echo esc_attr((string) $order_id); ?>">
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" style="display:none"></span>
                            <?php esc_html_e('Check payment processing', 'ledger-direct'); ?>
                        </button>
                    </form>
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
