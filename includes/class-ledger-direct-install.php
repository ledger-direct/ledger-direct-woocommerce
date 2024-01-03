<?php declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LedgerDirectInstall {

    public const TRANSIENT_INSTALLING = 'ledger-direct_installing';

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

        self::add_rewrite_rules();
        self::create_tables();
        self::create_pages();

        delete_transient( self::TRANSIENT_INSTALLING );
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

        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}xrpl_tx" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}xrpl_destination_tag" );
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
     * Add rewrite rules.
     *
     * @return void
     */
    private static function add_rewrite_rules(): void {
        add_rewrite_rule(
            'ledger-direct/payment/([a-z0-9-]+)[/]?$',
            'index.php?pagename=ledger-direct-payment&' . LedgerDirect::PAYMENT_IDENTIFIER . '=$matches[1]',
            'top'
        );
        flush_rewrite_rules();
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

        $tables = "
            CREATE TABLE {$wpdb->prefix}xrpl_tx (
                id int(10) unsigned NOT NULL AUTO_INCREMENT,
                ledger_index varchar(64) NOT NULL,
                hash varchar(64) NOT NULL,
                account varchar(35) NOT NULL,
                destination varchar(35) NOT NULL,
                destination_tag int(10) unsigned NOT NULL,
                date int(10) unsigned NOT NULL,
                meta text NOT NULL,
                tx text not null,
                PRIMARY KEY  (id)
            ) $collate;
            CREATE TABLE {$wpdb->prefix}xrpl_destination_tag (
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
            'ledger-direct' => [
                'name'    => 'ledger-direct',
                'title'   => 'Ledger Direct Payments',
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