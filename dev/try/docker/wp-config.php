<?php
/**
 * WordPress configuration of the try-it shop. Everything that differs between stacks comes from the
 * environment (dev/try/compose.yaml); the keys and salts are generated on the first start and kept in
 * the stack's state volume, so no two shops share them.
 */

define( 'DB_NAME', getenv( 'LD_DB_NAME' ) ?: 'wordpress' );
define( 'DB_USER', getenv( 'LD_DB_USER' ) ?: 'wordpress' );
define( 'DB_PASSWORD', getenv( 'LD_DB_PASSWORD' ) ?: 'wordpress' );
define( 'DB_HOST', getenv( 'LD_DB_HOST' ) ?: 'db' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wp_';

// The address the visitor's browser uses; the shop answers under it whatever port it is published on.
define( 'WP_HOME', rtrim( getenv( 'LD_BASE_URL' ) ?: 'http://localhost:8092', '/' ) );
define( 'WP_SITEURL', WP_HOME );

if ( is_file( '/var/lib/ledger-direct-try/keys.php' ) ) {
	require '/var/lib/ledger-direct-try/keys.php';
}

// The entrypoint runs WordPress's cron every minute: WP-Cron's own loopback request would go to the
// published port, which does not exist inside the container.
define( 'DISABLE_WP_CRON', true );
// The code is part of the image: no updates or installs from the admin, an image update brings them.
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'DISALLOW_FILE_MODS', true );
define( 'WP_DEBUG', false );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
