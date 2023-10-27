<?php

defined( 'ABSPATH' ) || exit;

class LedgerDirectInstall {
    public static function install(): void {
        // Check if we are not already running this routine.
        if ( self::is_installing() ) {
            return;
        }

        // If we made it till here nothing is running yet, lets set the transient now.
        set_transient( 'ledger-direct_installing', 'yes', MINUTE_IN_SECONDS * 10 );
        //wc_maybe_define_constant( 'lEDGER_DIRECT_INSTALLING', true );

        self::create_tables();
        self::maybe_create_pages();

        delete_transient( 'wc_installing' );
    }

    /**
     * Returns true if we're installing.
     *
     * @return bool
     */
    private static function is_installing(): bool {
        return 'yes' === get_transient( 'ledger-direct_installing' );
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
     * Create "ledger-direct" page on installation.
     *
     * @return void
     */
    public static function maybe_create_pages(): void  {
        self::create_pages();
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

        foreach ( $pages as $key => $page ) {
            // Use WooCommerce function to create pages
            wc_create_page(
                esc_sql( $page['name'] ),
                'ledger-direct_page_id',
                $page['title'],
                $page['content']
            );
        }
    }
}