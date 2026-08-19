<?php declare(strict_types=1);

defined( 'ABSPATH' ) || exit; // Exit if accessed directly

class LedgerDirectInstall {

    public const TRANSIENT_INSTALLING = 'ledger-direct_installing';

    public const DB_VERSION_OPTION = 'ledger_direct_db_version';

    /**
     * Current database schema version.
     *
     * Bump this whenever get_schema() changes, and add the corresponding
     * migration step to maybe_upgrade() so existing (live) installs are
     * migrated in place instead of relying on the activation hook, which
     * WordPress does not re-fire on a plain plugin update.
     */
    public const DB_VERSION = '1';

    /**
     * Install the plugin.
     *
     * @return void
     */
    public static function install(): void {
        if ( self::is_installing() ) {
            return;
        }

        set_transient( self::TRANSIENT_INSTALLING, 'yes', MINUTE_IN_SECONDS * 10 );

        self::create_tables();
        self::create_pages();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

        add_rewrite_endpoint('ledger-direct-payment', EP_ROOT);
        flush_rewrite_rules();

        delete_transient( self::TRANSIENT_INSTALLING );
    }

    /**
     * Runs any outstanding database migrations for already-installed sites.
     *
     * Hooked to `plugins_loaded` because WordPress only fires the activation
     * hook when a plugin is (re-)activated, not on a routine update - and
     * this plugin is live on WordPress.org, so existing installs must be
     * migrated automatically on the next request after an update.
     *
     * @return void
     */
    public static function maybe_upgrade(): void {
        $installedVersion = get_option( self::DB_VERSION_OPTION, '0' );

        if ( version_compare( $installedVersion, self::DB_VERSION, '>=' ) ) {
            return;
        }

        if ( self::is_installing() ) {
            return;
        }

        set_transient( self::TRANSIENT_INSTALLING, 'yes', MINUTE_IN_SECONDS * 10 );

        if ( version_compare( $installedVersion, '1', '<' ) ) {
            self::upgrade_to_1();
        }

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

        delete_transient( self::TRANSIENT_INSTALLING );
    }

    /**
     * Migration to schema version 1:
     * - unifies the destination-tag table under the `ledger_direct_xrpl_`
     *   prefix (previously split across `xrpl_destination_tag`, which
     *   XrplTxService actually read/wrote, and `ledger_direct_destination_tag`,
     *   which install.php created but nothing used)
     * - adds a UNIQUE KEY on `ledger_direct_xrpl_tx.hash`
     * - allows `ledger_direct_xrpl_tx.destination_tag` to be NULL, matching
     *   the `?? null` fallback already used when persisting transactions
     *
     * @return void
     */
    private static function upgrade_to_1(): void {
        global $wpdb;

        self::deduplicate_tx_table_by_hash();

        // dbDelta() both creates the new destination-tag table and alters the
        // existing tx table (adds the unique key, relaxes destination_tag to
        // NULL) based on the current get_schema() definition.
        self::create_tables();

        self::migrate_destination_tag_table( $wpdb->prefix . 'xrpl_destination_tag' );
        self::migrate_destination_tag_table( $wpdb->prefix . 'ledger_direct_destination_tag' );
    }

    /**
     * Removes duplicate rows (by `hash`) from the tx table before dbDelta
     * attempts to add a UNIQUE KEY - dbDelta silently skips adding a unique
     * index when duplicate values already exist.
     *
     * @return void
     */
    private static function deduplicate_tx_table_by_hash(): void {
        global $wpdb;

        $tx_table = $wpdb->prefix . 'ledger_direct_xrpl_tx';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tx_table ) ) !== $tx_table ) {
            return;
        }

        $wpdb->query(
            "DELETE t1 FROM {$tx_table} t1
             INNER JOIN {$tx_table} t2 ON t1.hash = t2.hash AND t1.id > t2.id"
        );
    }

    /**
     * Copies any reserved destination tags from a legacy table into the
     * current `ledger_direct_xrpl_destination_tag` table, then drops the
     * legacy table. Reserved tags have no meaningful value on their own
     * (they only prevent a future collision), so INSERT IGNORE is enough -
     * a tag that already exists in both tables can simply be dropped.
     *
     * @param string $legacy_table Fully-prefixed legacy table name.
     * @return void
     */
    private static function migrate_destination_tag_table( string $legacy_table ): void {
        global $wpdb;

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_table ) ) !== $legacy_table ) {
            return;
        }

        $current_table = $wpdb->prefix . 'ledger_direct_xrpl_destination_tag';

        $wpdb->query(
            "INSERT IGNORE INTO {$current_table} (destination_tag, account)
             SELECT destination_tag, account FROM {$legacy_table}"
        );

        $wpdb->query( "DROP TABLE IF EXISTS {$legacy_table}" );
    }

    /**
     * Deactivate the plugin.
     *
     * @return void
     */
    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    /**
     * Uninstall the plugin.
     *
     * @return void
     */
    public static function uninstall(): void {
        global $wpdb;

        $tx_table = $wpdb->prefix . 'ledger_direct_xrpl_tx';
        $wpdb->query( "DROP TABLE IF EXISTS {$tx_table}" );
        $dest_tag_table = $wpdb->prefix . 'ledger_direct_xrpl_destination_tag';
        $wpdb->query( "DROP TABLE IF EXISTS {$dest_tag_table}" );

        // Legacy table names kept around from before the destination-tag
        // table naming was unified (see upgrade_to_1()); drop them too in
        // case an install was removed before ever loading plugins_loaded.
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}xrpl_destination_tag" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ledger_direct_destination_tag" );

        delete_option( self::DB_VERSION_OPTION );
    }


    /**
     * Returns true if we're installing.
     *
     * @return bool
     */
    private static function is_installing(): bool {
        return 'yes' === get_transient( self::TRANSIENT_INSTALLING );
    }

    /**
     * Set up the database tables which the plugin needs to function.
     *
     * @return array Strings containing the results of the various update queries as returned by dbDelta.
     */
    public static function  create_tables(): array {
        global $wpdb;

        $wpdb->hide_errors();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        return dbDelta(self::get_schema());
    }

    /**
     * Get table schema formatted for use with dbDelta.
     *
     * @return string
     */
    private static function get_schema(): string {
        global $wpdb;

        $collate = '';

        if ( $wpdb->has_cap( 'collation' ) ) {
            $collate = $wpdb->get_charset_collate();
        }

        $tx_table = $wpdb->prefix . 'ledger_direct_xrpl_tx';
        $dest_tag_table = $wpdb->prefix . 'ledger_direct_xrpl_destination_tag';
        $tables = "
            CREATE TABLE {$tx_table} (
                id int(10) unsigned NOT NULL AUTO_INCREMENT,
                ledger_index varchar(64) NOT NULL,
                ctid varchar(16) NOT NULL,
                hash varchar(64) NOT NULL,
                account varchar(35) NOT NULL,
                destination varchar(35) NOT NULL,
                destination_tag int(10) unsigned NULL,
                date int(10) unsigned NOT NULL,
                meta text NOT NULL,
                tx text not null,
                PRIMARY KEY  (id),
                UNIQUE KEY  hash (hash)
            ) $collate;
            CREATE TABLE {$dest_tag_table} (
                destination_tag int(10) unsigned NOT NULL,
                account varchar(35) NOT NULL,
                PRIMARY KEY  (destination_tag)
            ) $collate;
        ";

        return $tables;
    }

    /**
     * Create pages that the plugin relies on, storing page IDs in variables.
     *
     * @return void
     */
    public static function create_pages() : void {
        $pages = [
            'ledger-direct-payment' => [
                'name'    => 'ledger-direct-payment',
                'title'   => 'Ledger Direct Payment',
                'content' => '',
            ]
        ];

        foreach ( $pages as $page ) {
            wc_create_page(
                esc_sql( $page['name'] ),
                'ledger-direct_page_id',
                $page['title'],
                $page['content']
            );
        }
    }
}