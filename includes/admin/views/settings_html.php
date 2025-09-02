<?php
    defined( 'ABSPATH' ) || exit;

    use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;
?>

<h2>
    <?php esc_html_e('LedgerDirect for WooCommerce','ledger-direct'); ?>
</h2>

<table class="form-table">
    <?php
        /** @var LedgerDirectPaymentGateway $context */
        $context->generate_settings_html();
    ?>
</table><table class="form-table">