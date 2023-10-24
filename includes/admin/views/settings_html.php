<?php
    use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;
?>

<h2>
    <?php _e('LedgerDirect for WooCommerce','ledger-direct'); ?>
</h2>

<table class="form-table">
    <?php
        /** @var LedgerDirectPaymentGateway $context */
        $context->generate_settings_html();
    ?>
</table><table class="form-table">