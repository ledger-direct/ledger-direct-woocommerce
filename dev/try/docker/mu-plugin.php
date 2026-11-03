<?php
/**
 * Plugin Name: LedgerDirect try shop
 * Description: What makes this WordPress a local try-it shop: no outgoing mail (there is no mail
 * server), and a reminder in the admin that its login is a demo login. Not part of the plugin.
 */

defined( 'ABSPATH' ) || exit;

// No mail server in the stack: mail counts as sent, nothing leaves the container.
add_filter( 'pre_wp_mail', '__return_true' );

add_action(
	'admin_notices',
	static function (): void {
		echo '<div class="notice notice-info"><p>'
			. esc_html( 'LedgerDirect try shop on the XRP Ledger testnet. Its admin login is a local demo login: never reuse it, never expose this shop.' )
			. '</p></div>';
	}
);
