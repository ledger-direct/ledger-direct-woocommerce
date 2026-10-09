<?php declare(strict_types=1);

defined( 'ABSPATH' ) || exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;

/**
 * The LedgerDirect payment page.
 *
 * Every sentence a customer reads is here and in the translation catalogues; one
 * block per payment state (waiting, partial, wrong_asset, expired), all rendered,
 * the server decides which starts visible. The script (@ledger-direct/payment-ui,
 * copied into public/) only switches blocks, inserts numbers and polls. A settled
 * order never renders this page; the controller redirects it.
 *
 * Markup contract: the package's src/README.md. No arithmetic on amounts here:
 * every value comes out of the presenter in src/ as a scalar - this file is not
 * scoped by the release build and must not name a core class.
 *
 * Whoever holds the order key holds the page - the same rule as WooCommerce's own
 * "order received" page, and the only one that works for the guest order a crypto
 * checkout mostly is, or for a customer coming back in another browser.
 *
 * @var WC_Order|null $ledger_direct_order
 * @var array<string, mixed>|null $ledger_direct_view
 */
global $ledger_direct_order, $ledger_direct_view;

$v = is_array($ledger_direct_view) ? $ledger_direct_view : null;
$state = is_array($v) ? (string) $v['state'] : '';
$asset = is_array($v) ? (string) $v['asset'] : '';
$has_wallet_app = is_array($v) && ($v['xaman_key'] !== '' || $v['wc_project'] !== '');

// The sentences with markup inside: the numbers are escaped, the tags are these and no others.
$allowed_amount_html = [
    'strong' => ['data-ld-paid' => true],
    'span' => ['data-ld-paid' => true, 'data-ld-shortfall' => true, 'data-ld-settled-amount' => true, 'data-ld-redirect-count' => true, 'data-ld-countdown' => true],
];
// The two notices with numbers inside, built here so the markup below stays one line each.
$partial_notice = '';
$wrong_asset_notice = '';
$wrong_asset_send = '';
if (is_array($v)) {
    $partial_notice = sprintf(
        /* translators: 1: amount received so far with asset symbol, 2: amount still missing with asset symbol */
        __('%1$s have arrived – thank you. %2$s are still missing.', 'ledger-direct'),
        '<strong><span data-ld-paid>' . esc_html((string) $v['amount_paid']) . '</span> ' . esc_html($asset) . '</strong>',
        '<strong><span data-ld-shortfall>' . esc_html((string) $v['shortfall']) . '</span> ' . esc_html($asset) . '</strong>'
    );
    $wrong_asset_notice = sprintf(
        /* translators: 1: amount that arrived, 2: the token this order expects */
        __('A payment of %1$s has arrived, but this order expects %2$s from the issuer named below. It cannot be credited.', 'ledger-direct'),
        '<strong data-ld-paid>' . esc_html((string) $v['amount_paid']) . '</strong>',
        '<strong>' . esc_html($asset) . '</strong>'
    );
    $wrong_asset_send = sprintf(
        /* translators: 1: amount still due with asset symbol */
        __('Please send %1$s – or contact us about the payment you already made.', 'ledger-direct'),
        '<strong><span data-ld-shortfall>' . esc_html((string) $v['shortfall']) . '</span> ' . esc_html($asset) . '</strong>'
    );
}
$copy_icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
$info_icon = '<svg class="ld-notice-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>';
$copy_labels = '<span data-ld-copy-label="idle">' . esc_html__('Copy', 'ledger-direct') . '</span><span data-ld-copy-label="done" hidden>' . esc_html__('Copied', 'ledger-direct') . '</span>';
$allowed_icon_html = [
    'svg' => ['class' => true, 'viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'aria-hidden' => true, 'width' => true, 'height' => true],
    'rect' => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true],
    'path' => ['d' => true],
    'circle' => ['cx' => true, 'cy' => true, 'r' => true],
    'span' => ['data-ld-copy-label' => true, 'hidden' => true],
];

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html(is_array($v) ? $v['page_title'] : get_bloginfo('name')); ?></title>
    <?php wp_head(); ?>
</head>

<body class="ld-body">

<?php
/*
 * The three cases that render no payment page: no such order, an order that no longer
 * needs one, an order of another gateway. One block, the sentences chosen above it.
 */
$ld_error = null;

if (!$ledger_direct_order || !is_a($ledger_direct_order, 'WC_Order')) {
    $ld_error = [
        'tone' => 'warn',
        'title' => __('Order not found', 'ledger-direct'),
        'text' => __('The requested order could not be found.', 'ledger-direct'),
        'url' => home_url(),
        'link' => __('Return to homepage', 'ledger-direct'),
    ];
} elseif (!$ledger_direct_order->needs_payment()) {
    // The one rule, WooCommerce's own: it knows the statuses this plugin adds (XRPL payment incomplete).
    $ld_error = [
        'tone' => 'info',
        'title' => __('Payment not required', 'ledger-direct'),
        /* translators: 1: order number, 2: order status label */
        'text' => sprintf(__('Order #%1$s is already %2$s.', 'ledger-direct'), $ledger_direct_order->get_order_number(), wc_get_order_status_name($ledger_direct_order->get_status())),
        'url' => $ledger_direct_order->get_view_order_url(),
        'link' => __('View Order', 'ledger-direct'),
    ];
} elseif ($ledger_direct_order->get_payment_method() !== LedgerDirectPaymentGateway::ID || !is_array($v)) {
    $ld_error = [
        'tone' => 'warn',
        'title' => __('Invalid payment method', 'ledger-direct'),
        'text' => __('This order was not paid with LedgerDirect.', 'ledger-direct'),
        'url' => home_url(),
        'link' => __('Return to homepage', 'ledger-direct'),
    ];
}

if ($ld_error !== null) {
    ?>
    <div class="ld-page" data-ld-page>
        <main class="ld-main">
            <div class="ld-card">
                <div class="ld-notice ld-notice--<?php echo esc_attr($ld_error['tone']); ?>" role="status">
                    <?php echo wp_kses($info_icon, $allowed_icon_html); ?>
                    <div>
                        <p><strong><?php echo esc_html($ld_error['title']); ?></strong></p>
                        <p><?php echo esc_html($ld_error['text']); ?></p>
                    </div>
                </div>
                <a class="ld-btn ld-btn--secondary" href="<?php echo esc_url($ld_error['url']); ?>"><?php echo esc_html($ld_error['link']); ?></a>
            </div>
        </main>
    </div>
    <?php
    wp_footer();
    echo '</body></html>';

    return;
}
?>

<div class="ld-page"
     data-ld-page
     data-ld-state="<?php echo esc_attr($state); ?>"
     data-ld-poll-url="<?php echo esc_url($v['poll_url']); ?>"
     data-ld-asset="<?php echo esc_attr($asset); ?>"
     data-ld-network="<?php echo esc_attr($v['network']); ?>"
     data-ld-explorer-base="<?php echo esc_url($v['explorer_base']); ?>"
     data-ld-quote-seconds="<?php echo (int) $v['quote_seconds']; ?>"
     data-ld-amount-requested="<?php echo esc_attr($v['amount']); ?>"
     data-ld-payment-uri="<?php echo esc_attr($v['payment_uri']); ?>"
     data-ld-wallets-src="<?php echo esc_url($v['wallets_src']); ?>"
     <?php if ($v['seconds_left'] !== null) { ?>data-ld-seconds-left="<?php echo (int) $v['seconds_left']; ?>"<?php } ?>
     <?php if ($v['amount_drops'] !== null) { ?>data-ld-amount-drops="<?php echo esc_attr($v['amount_drops']); ?>"<?php } ?>
     <?php if ($v['currency'] !== null) { ?>data-ld-currency="<?php echo esc_attr($v['currency']); ?>" data-ld-issuer="<?php echo esc_attr((string) $v['issuer']); ?>"<?php } ?>
     <?php if ($v['xaman_key'] !== '') { ?>data-ld-xaman-key="<?php echo esc_attr($v['xaman_key']); ?>"<?php } ?>
     <?php if ($v['wc_project'] !== '') { ?>data-ld-wc-project="<?php echo esc_attr($v['wc_project']); ?>"<?php } ?>
     style="--ld-accent: <?php echo esc_attr($v['accent']); ?>">

    <header class="ld-top">
        <a class="ld-shop" href="<?php echo esc_url($v['home_url']); ?>">
            <?php if ($v['logo']['url'] !== null) { ?>
                <span class="ld-shop-logo ld-shop-logo--image"><img src="<?php echo esc_url($v['logo']['url']); ?>" alt="<?php echo esc_attr($v['store_name']); ?>" width="160" height="32"></span>
            <?php } else { ?>
                <span class="ld-shop-logo" aria-hidden="true"><?php echo esc_html($v['logo']['monogram']); ?></span>
            <?php } ?>
            <span><?php echo esc_html($v['store_name']); ?></span>
        </a>
        <div class="ld-top-meta">
            <?php if ($v['is_testnet']) { ?>
                <span class="ld-badge ld-badge--testnet"><?php esc_html_e('Testnet – no real money', 'ledger-direct'); ?></span><br>
            <?php } ?>
            <?php esc_html_e('Order', 'ledger-direct'); ?> <strong><?php echo esc_html($v['order_number']); ?></strong>
        </div>
    </header>

    <main class="ld-main">
        <div class="ld-card">

            <div data-ld-open>
                <div class="ld-grid">
                    <section class="ld-col" aria-labelledby="ld-heading">
                        <h1 id="ld-heading" class="ld-sr"><?php esc_html_e('Complete your payment', 'ledger-direct'); ?></h1>

                        <?php /* Something arrived but the order is not paid. Neutral tone on purpose. */ ?>
                        <div class="ld-notice ld-notice--info" data-ld-block="partial" role="status" <?php echo $state !== 'partial' ? 'hidden' : ''; ?>>
                            <?php echo wp_kses($info_icon, $allowed_icon_html); ?>
                            <div>
                                <p><?php echo wp_kses($partial_notice, $allowed_amount_html); ?></p>
                                <div class="ld-progress" aria-hidden="true"><span data-ld-progress style="width: <?php echo (int) $v['paid_share']; ?>%"></span></div>
                                <p><?php esc_html_e('Please send the rest to the same address with the same destination tag.', 'ledger-direct'); ?></p>
                            </div>
                        </div>

                        <div class="ld-notice ld-notice--info" data-ld-block="wrong_asset" role="status" <?php echo $state !== 'wrong_asset' ? 'hidden' : ''; ?>>
                            <?php echo wp_kses($info_icon, $allowed_icon_html); ?>
                            <div>
                                <p><?php echo wp_kses($wrong_asset_notice, $allowed_amount_html); ?></p>
                                <p><?php echo wp_kses($wrong_asset_send, $allowed_amount_html); ?></p>
                            </div>
                        </div>

                        <div class="ld-notice ld-notice--warn" data-ld-block="expired" role="status" <?php echo $state !== 'expired' ? 'hidden' : ''; ?>>
                            <svg class="ld-notice-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                            <div>
                                <p><strong><?php esc_html_e('This amount is no longer valid.', 'ledger-direct'); ?></strong> <?php esc_html_e('The exchange rate may have moved since.', 'ledger-direct'); ?></p>
                                <p><?php esc_html_e('Already sent? Then do not send again – we will still recognise the payment.', 'ledger-direct'); ?></p>
                                <form method="post" action="<?php echo esc_url($v['page_url']); ?>">
                                    <input type="hidden" name="<?php echo esc_attr(LedgerDirect::ORDER_IDENTIFIER); ?>" value="<?php echo esc_attr($v['order_key']); ?>">
                                    <input type="hidden" name="ledger_direct_refresh" value="1">
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($v['refresh_nonce']); ?>">
                                    <button type="submit" class="ld-btn ld-btn--primary"><?php esc_html_e('Request a new amount', 'ledger-direct'); ?></button>
                                </form>
                            </div>
                        </div>

                        <p class="ld-eyebrow" data-ld-amount-label>
                            <span data-ld-label="due" <?php echo $state === 'partial' ? 'hidden' : ''; ?>><?php esc_html_e('Amount to send', 'ledger-direct'); ?></span>
                            <span data-ld-label="remaining" <?php echo $state !== 'partial' ? 'hidden' : ''; ?>><?php esc_html_e('Still to send', 'ledger-direct'); ?></span>
                        </p>
                        <div class="ld-amount">
                            <span class="ld-amount-value" data-ld-amount><?php echo esc_html($v['amount_due']); ?></span>
                            <span class="ld-amount-asset"><?php echo esc_html($asset); ?></span>
                            <button type="button" class="ld-copy" data-copy="amount" aria-label="<?php esc_attr_e('Copy amount', 'ledger-direct'); ?>"><?php echo wp_kses($copy_icon . $copy_labels, $allowed_icon_html); ?></button>
                        </div>
                        <p class="ld-fiat" data-ld-fiat>
                            <?php /* translators: %s: the order total in the shop currency */ ?>
                            <span data-ld-fiat-for="full" <?php echo $state === 'partial' ? 'hidden' : ''; ?>><?php echo esc_html(sprintf(__('equals %s', 'ledger-direct'), $v['fiat_display'])); ?></span>
                            <?php /* translators: 1: the requested amount, 2: the asset symbol, 3: the order total in the shop currency */ ?>
                            <span data-ld-fiat-for="partial" <?php echo $state !== 'partial' ? 'hidden' : ''; ?>><?php echo esc_html(sprintf(__('of %1$s %2$s in total (%3$s)', 'ledger-direct'), $v['amount'], $asset, $v['fiat_display'])); ?></span>
                        </p>

                        <?php if ($v['has_expiry']) { ?>
                            <div class="ld-timer" data-ld-timer data-ld-block="waiting" aria-live="off" <?php echo $state !== 'waiting' ? 'hidden' : ''; ?>>
                                <?php
                                echo wp_kses(sprintf(
                                    /* translators: %s: the countdown, filled in by the script */
                                    __('Amount guaranteed for %s minutes', 'ledger-direct'),
                                    '<strong data-ld-countdown>' . (int) ($v['seconds_left'] ?? 0) . '</strong>'
                                ), ['strong' => ['data-ld-countdown' => true]]);
                                ?>
                                <div class="ld-timer-bar" aria-hidden="true"><span data-ld-timer-bar style="width: 100%"></span></div>
                            </div>
                        <?php } ?>

                        <ol class="ld-steps">
                            <li>
                                <div class="ld-field-label"><span class="ld-step-no">1</span> <?php esc_html_e('Receiving address', 'ledger-direct'); ?></div>
                                <div class="ld-field">
                                    <span class="ld-field-value" data-ld-account data-value="<?php echo esc_attr($v['destination_account']); ?>"><?php echo esc_html($v['destination_account']); ?></span>
                                    <button type="button" class="ld-copy" data-copy="account" aria-label="<?php esc_attr_e('Copy address', 'ledger-direct'); ?>"><?php echo wp_kses($copy_icon . $copy_labels, $allowed_icon_html); ?></button>
                                </div>
                            </li>
                            <li>
                                <div class="ld-field-label"><span class="ld-step-no">2</span> <?php esc_html_e('Destination tag', 'ledger-direct'); ?> <span class="ld-badge ld-badge--required"><?php esc_html_e('Required', 'ledger-direct'); ?></span></div>
                                <div class="ld-field ld-field--key">
                                    <span class="ld-field-value" data-ld-tag data-value="<?php echo (int) $v['destination_tag']; ?>"><?php echo (int) $v['destination_tag']; ?></span>
                                    <button type="button" class="ld-copy" data-copy="tag" aria-label="<?php esc_attr_e('Copy destination tag', 'ledger-direct'); ?>"><?php echo wp_kses($copy_icon . $copy_labels, $allowed_icon_html); ?></button>
                                </div>
                                <p class="ld-hint"><?php esc_html_e('Without this tag we cannot match the payment to your order.', 'ledger-direct'); ?></p>
                            </li>
                            <?php if ($v['issuer'] !== null && $v['issuer'] !== '') { ?>
                                <li>
                                    <div class="ld-field-label"><span class="ld-step-no">3</span> <?php esc_html_e('Token and issuer', 'ledger-direct'); ?></div>
                                    <div class="ld-field">
                                        <span class="ld-field-value"><?php echo esc_html($asset); ?> · <span data-ld-issuer data-value="<?php echo esc_attr($v['issuer']); ?>"><?php echo esc_html($v['issuer']); ?></span></span>
                                        <button type="button" class="ld-copy" data-copy="issuer" aria-label="<?php esc_attr_e('Copy issuer', 'ledger-direct'); ?>"><?php echo wp_kses($copy_icon . $copy_labels, $allowed_icon_html); ?></button>
                                    </div>
                                    <?php /* translators: %s: the token symbol (RLUSD, USDC) */ ?>
                                    <p class="ld-hint"><?php echo esc_html(sprintf(__('Only %s from exactly this issuer is credited. Your wallet needs a trust line for it.', 'ledger-direct'), $asset)); ?></p>
                                </li>
                            <?php } ?>
                        </ol>

                        <details class="ld-exchange">
                            <summary><?php esc_html_e('Paying from an exchange?', 'ledger-direct'); ?></summary>
                            <p><?php esc_html_e("The destination tag is required, and the amount must still arrive in full after the exchange's fee.", 'ledger-direct'); ?></p>
                        </details>
                    </section>

                    <aside class="ld-col ld-col--side" aria-label="<?php esc_attr_e('Pay with a wallet', 'ledger-direct'); ?>">
                        <details class="ld-qr" data-ld-qr-details open>
                            <summary><span class="ld-qr-sum-open"><?php esc_html_e('Scan with a wallet app', 'ledger-direct'); ?></span><span class="ld-qr-sum-closed"><?php esc_html_e('Show QR code ▾', 'ledger-direct'); ?></span></summary>
                            <div class="ld-qr-box<?php echo $state === 'expired' ? ' is-void' : ''; ?>" data-ld-qr-box>
                                <?php /* Server-rendered code of the same payment request, for a browser without scripts; the script redraws it. */ ?>
                                <div data-ld-qr data-ld-qr-label="<?php esc_attr_e('QR code with address, destination tag and amount', 'ledger-direct'); ?>"><img src="<?php echo esc_attr($v['qr_data_uri']); ?>" alt="<?php esc_attr_e('QR code with address, destination tag and amount', 'ledger-direct'); ?>" width="220" height="220"></div>
                                <div class="ld-qr-void" data-ld-qr-void <?php echo $state !== 'expired' ? 'hidden' : ''; ?>><?php esc_html_e('Expired – please request a new amount', 'ledger-direct'); ?></div>
                            </div>
                            <p class="ld-qr-caption"><?php esc_html_e('Scan with Xaman or another XRPL wallet. Address, destination tag and amount are filled in – please check them before sending.', 'ledger-direct'); ?></p>
                        </details>

                        <?php /* Browser wallets, loaded only on click. Every sentence the module can show is rendered here. */ ?>
                        <div data-ld-wallet-section <?php echo $has_wallet_app ? 'data-ld-wallet-mobile' : ''; ?> <?php echo $state === 'expired' ? 'hidden' : ''; ?>>
                            <div class="ld-or"><?php esc_html_e('or', 'ledger-direct'); ?></div>
                            <div data-ld-wallet-desktop>
                                <button type="button" class="ld-btn ld-btn--secondary ld-btn--block" data-ld-wallet-toggle aria-expanded="false" aria-controls="ld-wallets">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="6" width="20" height="14" rx="2"/><path d="M16 13h2M2 10h20"/></svg>
                                    <?php esc_html_e('Pay with a browser wallet', 'ledger-direct'); ?>
                                </button>
                                <div class="ld-wallets" id="ld-wallets" data-ld-wallets hidden>
                                    <p class="ld-hint" data-ld-wallet-status="loading" hidden><?php esc_html_e('Looking for wallets …', 'ledger-direct'); ?></p>
                                    <div data-ld-wallet-list></div>
                                    <p class="ld-hint" data-ld-wallet-status="none" hidden><?php esc_html_e('No browser wallet found. Use the QR code or copy the details.', 'ledger-direct'); ?></p>
                                    <p class="ld-hint" data-ld-wallet-status="hint" hidden><?php esc_html_e('The wallet fills in the payment. You confirm it there.', 'ledger-direct'); ?></p>
                                </div>
                            </div>
                            <?php if ($has_wallet_app) { ?>
                                <button type="button" class="ld-btn ld-btn--secondary ld-btn--block" data-ld-wallet-app data-ld-wallet-id="<?php echo $v['xaman_key'] !== '' ? 'xaman' : 'walletconnect'; ?>"><?php esc_html_e('Open in wallet app', 'ledger-direct'); ?></button>
                            <?php } ?>
                            <p class="ld-hint" data-ld-wallet-message aria-live="polite" hidden></p>
                            <span hidden data-ld-wallet-text="found"><?php esc_html_e('found', 'ledger-direct'); ?></span>
                            <span hidden data-ld-wallet-text="confirm"><?php esc_html_e('Confirm in your wallet …', 'ledger-direct'); ?></span>
                            <span hidden data-ld-wallet-text="submitted"><?php esc_html_e('Sent – we are checking for it.', 'ledger-direct'); ?></span>
                            <span hidden data-ld-wallet-text="error-unavailable"><?php esc_html_e('The wallet does not respond. Is the extension installed and unlocked?', 'ledger-direct'); ?></span>
                            <span hidden data-ld-wallet-text="error-network"><?php esc_html_e('The connection to the wallet failed – please try again.', 'ledger-direct'); ?></span>
                            <?php /* translators: %network% is replaced by the script with the network's name */ ?>
                            <span hidden data-ld-wallet-text="error-mismatch"><?php esc_html_e('Your wallet is set to another network. Please switch to %network%.', 'ledger-direct'); ?></span>
                            <span hidden data-ld-wallet-text="error-other"><?php esc_html_e('The payment through the wallet failed. Please use the QR code or copy the details.', 'ledger-direct'); ?></span>
                            <span hidden data-ld-wallet-text="network-mainnet"><?php esc_html_e('XRPL Mainnet', 'ledger-direct'); ?></span>
                            <span hidden data-ld-wallet-text="network-testnet"><?php esc_html_e('XRPL Testnet', 'ledger-direct'); ?></span>
                        </div>

                        <dl class="ld-details">
                            <dt><?php esc_html_e('Order total', 'ledger-direct'); ?></dt><dd><?php echo esc_html($v['fiat_display']); ?></dd>
                            <?php /* translators: 1: the asset symbol, 2: the exchange rate, 3: the shop currency */ ?>
                            <dt><?php esc_html_e('Rate', 'ledger-direct'); ?></dt><dd><?php echo esc_html(sprintf(__('1 %1$s = %2$s %3$s', 'ledger-direct'), $asset, $v['rate_display'], $v['shop_currency'])); ?></dd>
                            <dt><?php esc_html_e('Network', 'ledger-direct'); ?></dt><dd><?php echo $v['is_testnet'] ? esc_html__('XRPL Testnet', 'ledger-direct') : esc_html__('XRPL Mainnet', 'ledger-direct'); ?></dd>
                        </dl>
                    </aside>
                </div>

                <div class="ld-status">
                    <div class="ld-status-text" aria-live="polite">
                        <span class="ld-pulse" aria-hidden="true"></span>
                        <span data-ld-status-for="waiting" <?php echo $state !== 'waiting' ? 'hidden' : ''; ?>><?php esc_html_e('Waiting for your payment. This page updates by itself.', 'ledger-direct'); ?></span>
                        <span data-ld-status-for="partial" <?php echo $state !== 'partial' ? 'hidden' : ''; ?>><?php esc_html_e('Partial payment received. Waiting for the rest.', 'ledger-direct'); ?></span>
                        <span data-ld-status-for="wrong_asset" <?php echo $state !== 'wrong_asset' ? 'hidden' : ''; ?>><?php esc_html_e('Payment in the wrong token received. Waiting for the right amount.', 'ledger-direct'); ?></span>
                        <span data-ld-status-for="expired" <?php echo $state !== 'expired' ? 'hidden' : ''; ?>><?php esc_html_e('A late payment of the old amount is still recognised.', 'ledger-direct'); ?></span>
                    </div>
                    <?php /* The manual path, and the only one a browser without JavaScript has: a reload of this page, which syncs and settles. The script turns it into a poll. */ ?>
                    <form method="get" action="<?php echo esc_url($v['page_url']); ?>" data-ld-check-form>
                        <input type="hidden" name="<?php echo esc_attr(LedgerDirect::ORDER_IDENTIFIER); ?>" value="<?php echo esc_attr($v['order_key']); ?>">
                        <button type="submit" class="ld-btn ld-btn--secondary" data-ld-check>
                            <span data-ld-check-label="idle"><?php esc_html_e('Check payment now', 'ledger-direct'); ?></span>
                            <span data-ld-check-label="busy" hidden><span class="ld-spinner" aria-hidden="true"></span> <?php esc_html_e('Checking …', 'ledger-direct'); ?></span>
                        </button>
                    </form>
                    <p class="ld-toast" data-ld-toast hidden><?php esc_html_e('No payment found yet. A transaction usually takes only a few seconds – please check again in a moment.', 'ledger-direct'); ?></p>
                </div>
            </div>

            <?php /* Shown by the script when the poll reports settled; a page loaded for a paid order is redirected by the controller. */ ?>
            <div class="ld-success" data-ld-success hidden>
                <div class="ld-success-icon" aria-hidden="true"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg></div>
                <h2><?php esc_html_e('Payment received', 'ledger-direct'); ?></h2>
                <?php /* translators: %s: the settled amount with asset symbol, filled in by the script */ ?>
                <p><?php echo wp_kses(sprintf(__('%s have arrived.', 'ledger-direct'), '<strong><span data-ld-settled-amount></span> ' . esc_html($asset) . '</strong>'), $allowed_amount_html); ?></p>
                <p data-ld-hash-row hidden><?php esc_html_e('Transaction', 'ledger-direct'); ?> <a data-ld-hash href="#" target="_blank" rel="noopener"><code></code></a></p>
                <?php /* translators: %s: the seconds until the redirect, counted down by the script */ ?>
                <p><?php echo wp_kses(sprintf(__('Continuing to your order in %s s', 'ledger-direct'), '<span data-ld-redirect-count>5</span>'), $allowed_amount_html); ?></p>
                <a class="ld-btn ld-btn--primary" data-ld-redirect-link href="<?php echo esc_url($v['redirect_url']); ?>"><?php esc_html_e('Continue to your order', 'ledger-direct'); ?></a>
            </div>

        </div>
    </main>

    <footer class="ld-foot">
        <a href="<?php echo esc_url($v['cart_url']); ?>"><?php esc_html_e('Back to cart', 'ledger-direct'); ?></a>
        <span><?php esc_html_e('Paid directly on the XRP Ledger, with no intermediary.', 'ledger-direct'); ?></span>
    </footer>
</div>

<?php wp_footer(); ?>
</body>
</html>
