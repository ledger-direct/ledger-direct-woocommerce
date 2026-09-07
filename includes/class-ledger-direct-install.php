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
     *
     * 1 - unified destination-tag table name, unique key on tx.hash
     * 2 - core alignment: numeric ledger_index, per-account destination-tag
     *     counter instead of a list of issued tags (see upgrade_to_2())
     */
    public const DB_VERSION = '2';

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

        // A fresh install gets the current schema directly; an install that
        // already has tables from an earlier version goes through the same
        // upgrade steps as a routine plugin update would.
        if ( self::table_exists( self::tx_table() ) ) {
            self::run_upgrades( (string) get_option( self::DB_VERSION_OPTION, '0' ) );
        } else {
            self::create_tables();
        }

        if ( function_exists( 'wc_create_page' ) ) {
            self::create_pages();
        }

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
        $installedVersion = (string) get_option( self::DB_VERSION_OPTION, '0' );

        if ( version_compare( $installedVersion, self::DB_VERSION, '>=' ) ) {
            return;
        }

        if ( self::is_installing() ) {
            return;
        }

        set_transient( self::TRANSIENT_INSTALLING, 'yes', MINUTE_IN_SECONDS * 10 );

        self::run_upgrades( $installedVersion );

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

        delete_transient( self::TRANSIENT_INSTALLING );
    }

    /**
     * Applies every schema step above $installedVersion, in order. Each step
     * only prepares existing tables (type changes, cleanup); the current
     * schema is applied once at the end via dbDelta, which adds whatever
     * columns and keys are still missing. Every step is idempotent, so
     * re-running after a failed step is safe.
     *
     * @param string $installedVersion
     * @return void
     */
    private static function run_upgrades( string $installedVersion ): void {
        if ( version_compare( $installedVersion, '1', '<' ) ) {
            self::upgrade_to_1();
        }

        if ( version_compare( $installedVersion, '2', '<' ) ) {
            self::upgrade_to_2();
        }

        self::create_tables();
    }

    /**
     * Schema version 1: UNIQUE KEY on tx.hash. dbDelta silently skips a
     * unique index when duplicate values exist, so duplicates go first.
     *
     * Historically this step also unified the destination-tag table name
     * (created as `ledger_direct_destination_tag`, used as
     * `xrpl_destination_tag`). Version 2 replaces that table wholesale, so
     * the legacy tables are simply dropped there instead of copied first.
     *
     * @return void
     */
    private static function upgrade_to_1(): void {
        self::deduplicate_tx_table_by_hash();
    }

    /**
     * Schema version 2 - aligns the schema with what
     * hardcastle/ledger-direct-core expects (INVARIANTS.md, "Tables"):
     *
     * - `ledger_index` becomes BIGINT UNSIGNED: the sync resumes from
     *   MAX(ledger_index), and MAX() over a VARCHAR sorts lexicographically
     *   ("9" > "10"), so the sync would resume at the wrong point on the next
     *   digit rollover.
     * - `ledger_direct_xrpl_destination_tag` changes from a list of issued
     *   tags to a per-account counter, which the core's repository port
     *   increments atomically and turns into tags via a fixed permutation.
     *   Nothing is carried over: the old rows are random tags, the new row is
     *   a counter - there is no mapping between the two. Matching a payment
     *   to an order goes through the tx table, so orders quoted before the
     *   upgrade keep working with the tag stored on the order itself.
     *
     * Guarded on the legacy shape (a `destination_tag` column) so a re-run
     * cannot drop a live counter table: that would hand out destination
     * tags that were already issued.
     *
     * @return void
     */
    private static function upgrade_to_2(): void {
        global $wpdb;

        $tx_table = self::tx_table();

        if ( self::table_exists( $tx_table ) && ! self::column_has_type( $tx_table, 'ledger_index', 'bigint' ) ) {
            // Explicit ALTER: dbDelta only reliably adds columns/keys, it does
            // not reliably change an existing column's type.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( "ALTER TABLE {$tx_table} MODIFY ledger_index bigint(20) unsigned NOT NULL" );
        }

        $dest_tag_table = self::destination_tag_table();

        $legacy_tables = [
            $wpdb->prefix . 'xrpl_destination_tag',
            $wpdb->prefix . 'ledger_direct_destination_tag',
        ];

        if ( self::column_exists( $dest_tag_table, 'destination_tag' ) ) {
            $legacy_tables[] = $dest_tag_table;
        }

        foreach ( $legacy_tables as $legacy_table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( "DROP TABLE IF EXISTS {$legacy_table}" );
        }
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

        $tx_table = self::tx_table();

        if ( ! self::table_exists( $tx_table ) ) {
            return;
        }

        $wpdb->query(
            "DELETE t1 FROM {$tx_table} t1
             INNER JOIN {$tx_table} t2 ON t1.hash = t2.hash AND t1.id > t2.id"
        );
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

        $tables = [
            self::tx_table(),
            self::destination_tag_table(),
            // Legacy table names from before the destination-tag table naming
            // was unified; drop them too in case an install was removed before
            // ever running the upgrade routine.
            $wpdb->prefix . 'xrpl_destination_tag',
            $wpdb->prefix . 'ledger_direct_destination_tag',
        ];

        foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        }

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
     * Fully prefixed name of the synced-transactions table.
     *
     * @return string
     */
    public static function tx_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'ledger_direct_xrpl_tx';
    }

    /**
     * Fully prefixed name of the destination-tag counter table.
     *
     * @return string
     */
    public static function destination_tag_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'ledger_direct_xrpl_destination_tag';
    }

    /**
     * @param string $table Fully prefixed table name.
     * @return bool
     */
    private static function table_exists( string $table ): bool {
        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /**
     * @param string $table Fully prefixed table name.
     * @param string $column
     * @return bool
     */
    private static function column_exists( string $table, string $column ): bool {
        if ( ! self::table_exists( $table ) ) {
            return false;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
    }

    /**
     * @param string $table Fully prefixed table name.
     * @param string $column
     * @param string $type  Type prefix to look for, e.g. 'bigint'.
     * @return bool
     */
    private static function column_has_type( string $table, string $column, string $type ): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ), ARRAY_A );

        return is_array( $row ) && str_starts_with( strtolower( (string) $row['Type'] ), strtolower( $type ) );
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
     * Logical names and shapes follow the core's INVARIANTS.md ("Tables");
     * only the $wpdb->prefix is this adapter's business.
     *
     * @return string
     */
    private static function get_schema(): string {
        global $wpdb;

        $collate = '';

        if ( $wpdb->has_cap( 'collation' ) ) {
            $collate = $wpdb->get_charset_collate();
        }

        $tx_table = self::tx_table();
        $dest_tag_table = self::destination_tag_table();
        $tables = "
            CREATE TABLE {$tx_table} (
                id int(10) unsigned NOT NULL AUTO_INCREMENT,
                ledger_index bigint(20) unsigned NOT NULL,
                ctid varchar(16) NOT NULL,
                hash varchar(64) NOT NULL,
                account varchar(35) NOT NULL,
                destination varchar(35) NOT NULL,
                destination_tag int(10) unsigned NULL,
                date int(10) unsigned NOT NULL,
                meta text NOT NULL,
                tx text NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY  hash (hash),
                KEY  destination (destination,destination_tag)
            ) $collate;
            CREATE TABLE {$dest_tag_table} (
                destination_account varchar(64) NOT NULL,
                sequence int(10) unsigned NOT NULL,
                PRIMARY KEY  (destination_account)
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
