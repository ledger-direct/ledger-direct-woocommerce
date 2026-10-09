<?php declare(strict_types=1);
/**
 * The LedgerDirect panel on the admin order page.
 *
 * Expects $ledger_direct_panel, the scalars from OrderPanelPresenter. This
 * file must not name a core class: the release build prefixes the core's
 * namespace and leaves includes/ as it is.
 *
 * @var array<string, mixed> $ledger_direct_panel
 */

defined('ABSPATH') || exit;

$ld_panel = $ledger_direct_panel;
$ld_asset = (string) $ld_panel['asset'];
?>
<div class="ledger-direct-order-panel">
    <p>
        <strong><?php echo esc_html($ld_panel['state_label']); ?></strong><br>
        <span class="description"><?php echo esc_html($ld_asset . ' · ' . $ld_panel['network']); ?></span>
    </p>
    <table class="widefat striped" style="margin-bottom: 8px;">
        <tbody>
        <tr>
            <th scope="row"><?php esc_html_e('Requested', 'ledger-direct'); ?></th>
            <td><?php echo esc_html($ld_panel['amount_requested'] . ' ' . $ld_asset); ?></td>
        </tr>
        <?php if ($ld_panel['amount_paid'] !== null) { ?>
        <tr>
            <th scope="row"><?php esc_html_e('Received', 'ledger-direct'); ?></th>
            <td><?php echo esc_html($ld_panel['amount_paid'] . ' ' . ($ld_panel['state'] === 'wrong_asset' ? '' : $ld_asset)); ?></td>
        </tr>
        <?php } ?>
        <?php if ($ld_panel['shortfall'] !== null) { ?>
        <tr>
            <th scope="row"><?php esc_html_e('Still due', 'ledger-direct'); ?></th>
            <td><?php echo esc_html($ld_panel['shortfall'] . ' ' . $ld_asset); ?></td>
        </tr>
        <?php } ?>
        <tr>
            <th scope="row"><?php esc_html_e('Rate', 'ledger-direct'); ?></th>
            <td><?php echo esc_html($ld_panel['rate'] . ' ' . $ld_panel['pairing']); ?></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Account', 'ledger-direct'); ?></th>
            <td><code><?php echo esc_html($ld_panel['destination_account']); ?></code></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Destination tag', 'ledger-direct'); ?></th>
            <td><code><?php echo esc_html($ld_panel['destination_tag']); ?></code></td>
        </tr>
        <?php if ($ld_panel['issuer'] !== null) { ?>
        <tr>
            <th scope="row"><?php esc_html_e('Issuer', 'ledger-direct'); ?></th>
            <td><code><?php echo esc_html($ld_panel['issuer']); ?></code></td>
        </tr>
        <?php } ?>
        <?php if ($ld_panel['expiry'] !== null) { ?>
        <tr>
            <th scope="row"><?php esc_html_e('Quote valid until', 'ledger-direct'); ?></th>
            <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $ld_panel['expiry'])); ?></td>
        </tr>
        <?php } ?>
        <?php if ($ld_panel['hash'] !== null) { ?>
        <tr>
            <th scope="row"><?php esc_html_e('Transaction', 'ledger-direct'); ?></th>
            <td>
                <?php if ($ld_panel['explorer'] !== null) { ?>
                <a href="<?php echo esc_url($ld_panel['explorer'] . $ld_panel['hash']); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html(substr((string) $ld_panel['hash'], 0, 12) . '…'); ?></code></a>
                <?php } else { ?>
                <code><?php echo esc_html($ld_panel['hash']); ?></code>
                <?php } ?>
            </td>
        </tr>
        <?php } ?>
        </tbody>
    </table>
    <?php if ($ld_panel['transactions'] !== []) { ?>
    <p><strong><?php esc_html_e('Transactions on this destination tag', 'ledger-direct'); ?></strong></p>
    <ul style="margin: 0;">
        <?php foreach ($ld_panel['transactions'] as $ld_tx) { ?>
        <li>
            <?php echo esc_html($ld_tx['date']); ?> ·
            <?php if ($ld_tx['delivered'] === null) { ?>
                <?php esc_html_e('no delivered amount', 'ledger-direct'); ?>
            <?php } else { ?>
                <?php echo esc_html($ld_tx['delivered'] . ' ' . ($ld_tx['is_issued'] ? __('(token)', 'ledger-direct') : 'XRP')); ?>
            <?php } ?>
            ·
            <?php if ($ld_tx['explorer_url'] !== null) { ?>
            <a href="<?php echo esc_url($ld_tx['explorer_url']); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html(substr((string) $ld_tx['hash'], 0, 12) . '…'); ?></code></a>
            <?php } else { ?>
            <code><?php echo esc_html($ld_tx['hash']); ?></code>
            <?php } ?>
        </li>
        <?php } ?>
    </ul>
    <?php } ?>
    <p class="description"><?php esc_html_e('Read-only. Settling is the sync\'s job; to accept a short payment, change the order status as for any other payment method.', 'ledger-direct'); ?></p>
</div>
