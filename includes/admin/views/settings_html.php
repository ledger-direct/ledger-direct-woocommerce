<?php declare(strict_types=1);

    defined( 'ABSPATH' ) || exit; // Exit if accessed directly

    use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;
?>

<h2>
    <?php esc_html_e('LedgerDirect for WooCommerce', 'ledger-direct'); ?>
</h2>

<?php
    // What the saved receiving account lacks; the settings below stay as they are.
    foreach (LedgerDirectAdmin::account_check_notices() as $ld_notice) {
        echo '<div class="notice inline ' . esc_attr($ld_notice['class']) . '"><p>' . esc_html($ld_notice['text']) . '</p></div>';
    }
?>

<table class="form-table">
    <?php
        /** @var LedgerDirectPaymentGateway $context */
        $context->generate_settings_html();
    ?>
</table>