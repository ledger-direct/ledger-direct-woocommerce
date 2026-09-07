<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use LedgerDirectInstall;

/**
 * Exercises the versioned schema upgrade against a real database, starting
 * from the shapes earlier plugin versions actually created.
 */
class LedgerDirectInstallTest extends TestCase
{
    private string $txTable;
    private string $tagTable;

    protected function setUp(): void
    {
        parent::setUp();

        // WP_UnitTestCase turns CREATE/DROP TABLE into temporary tables, which
        // SHOW TABLES cannot see and which cannot be self-joined - the real
        // upgrade runs against real tables, so this test does too.
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);

        global $wpdb;
        $this->txTable = $wpdb->prefix . 'ledger_direct_xrpl_tx';
        $this->tagTable = $wpdb->prefix . 'ledger_direct_xrpl_destination_tag';
    }

    protected function tearDown(): void
    {
        // Leave the current schema behind for the other tests.
        $this->dropAll();
        LedgerDirectInstall::create_tables();
        update_option(LedgerDirectInstall::DB_VERSION_OPTION, LedgerDirectInstall::DB_VERSION);

        parent::tearDown();
    }

    public function testUpgradeFromLegacySchemaConvertsTypesAndReplacesTagTable(): void
    {
        global $wpdb;

        $this->createLegacySchema();

        $wpdb->query("INSERT INTO {$this->txTable} (ledger_index, ctid, hash, account, destination, destination_tag, date, meta, tx)
            VALUES ('99999999', 'C', 'HASH-A', 'rA', 'rDest', 12345, 1, '{}', '{}'),
                   ('100000000', 'C', 'HASH-B', 'rA', 'rDest', 12346, 2, '{}', '{}'),
                   ('100000001', 'C', 'HASH-B', 'rA', 'rDest', 12346, 3, '{}', '{}')");
        $wpdb->query("INSERT INTO {$this->tagTable} (destination_tag, account) VALUES (12345, 'rDest')");
        $wpdb->query("INSERT INTO {$wpdb->prefix}xrpl_destination_tag (destination_tag, account) VALUES (12346, 'rDest')");

        update_option(LedgerDirectInstall::DB_VERSION_OPTION, '0');

        LedgerDirectInstall::maybe_upgrade();

        $this->assertSame(LedgerDirectInstall::DB_VERSION, get_option(LedgerDirectInstall::DB_VERSION_OPTION));

        $ledgerIndex = $wpdb->get_row("SHOW COLUMNS FROM {$this->txTable} LIKE 'ledger_index'", ARRAY_A);
        $this->assertStringStartsWith('bigint', strtolower($ledgerIndex['Type']));

        // MAX() is numeric now: 100000000 > 99999999, which a varchar got wrong.
        $this->assertSame('100000000', (string) $wpdb->get_var("SELECT MAX(ledger_index) FROM {$this->txTable}"));

        // Duplicate hash removed, unique key in place, index on (destination, destination_tag).
        $this->assertSame(2, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->txTable}"));
        $indexes = array_column($wpdb->get_results("SHOW INDEX FROM {$this->txTable}", ARRAY_A), 'Key_name');
        $this->assertContains('hash', $indexes);
        $this->assertContains('destination', $indexes);

        // Tag table is a per-account counter now, legacy tables are gone.
        $this->assertNotEmpty($wpdb->get_var("SHOW COLUMNS FROM {$this->tagTable} LIKE 'sequence'"));
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->tagTable}"));
        $this->assertNull($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'xrpl_destination_tag')));
    }

    public function testUpgradeIsIdempotentAndNeverResetsACounter(): void
    {
        global $wpdb;

        $this->dropAll();
        LedgerDirectInstall::create_tables();
        $wpdb->query("INSERT INTO {$this->tagTable} (destination_account, sequence) VALUES ('rDest', 41)");

        update_option(LedgerDirectInstall::DB_VERSION_OPTION, '1');
        LedgerDirectInstall::maybe_upgrade();

        $this->assertSame(41, (int) $wpdb->get_var("SELECT sequence FROM {$this->tagTable} WHERE destination_account = 'rDest'"));

        update_option(LedgerDirectInstall::DB_VERSION_OPTION, '0');
        LedgerDirectInstall::maybe_upgrade();

        $this->assertSame(41, (int) $wpdb->get_var("SELECT sequence FROM {$this->tagTable} WHERE destination_account = 'rDest'"));
    }

    public function testMaybeUpgradeIsANoopWhenAlreadyCurrent(): void
    {
        global $wpdb;

        $this->dropAll();
        LedgerDirectInstall::create_tables();
        $wpdb->query("INSERT INTO {$this->tagTable} (destination_account, sequence) VALUES ('rDest', 7)");
        update_option(LedgerDirectInstall::DB_VERSION_OPTION, LedgerDirectInstall::DB_VERSION);

        LedgerDirectInstall::maybe_upgrade();

        $this->assertSame(7, (int) $wpdb->get_var("SELECT sequence FROM {$this->tagTable} WHERE destination_account = 'rDest'"));
    }

    /**
     * The tables as created by 0.10.x: varchar ledger index, signed-safe
     * random tags kept in a list, plus the misnamed table XrplTxService
     * used to write to.
     */
    private function createLegacySchema(): void
    {
        global $wpdb;

        $this->dropAll();

        $wpdb->query("CREATE TABLE {$this->txTable} (
            id int(10) unsigned NOT NULL AUTO_INCREMENT,
            ledger_index varchar(64) NOT NULL,
            ctid varchar(16) NOT NULL,
            hash varchar(64) NOT NULL,
            account varchar(35) NOT NULL,
            destination varchar(35) NOT NULL,
            destination_tag int(10) unsigned NOT NULL,
            date int(10) unsigned NOT NULL,
            meta text NOT NULL,
            tx text NOT NULL,
            PRIMARY KEY (id)
        )");
        $wpdb->query("CREATE TABLE {$this->tagTable} (
            destination_tag int(10) unsigned NOT NULL,
            account varchar(35) NOT NULL,
            PRIMARY KEY (destination_tag)
        )");
        $wpdb->query("CREATE TABLE {$wpdb->prefix}xrpl_destination_tag (
            destination_tag int(10) unsigned NOT NULL,
            account varchar(35) NOT NULL,
            PRIMARY KEY (destination_tag)
        )");
    }

    private function dropAll(): void
    {
        global $wpdb;

        foreach ([
            $this->txTable,
            $this->tagTable,
            $wpdb->prefix . 'xrpl_destination_tag',
            $wpdb->prefix . 'ledger_direct_destination_tag',
        ] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
    }
}
