<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Service\ServiceFactory;

/**
 * After a testnet reset the stored cursor sits above the live ledger. The core's
 * SyncService remembers the reset in the PSR-16 cache it is given, so only the
 * first sync per account sends the failing request. Without the cache every
 * sync would send it again.
 */
class SyncAfterTestnetResetTest extends TestCase
{
    private XrplNetworkStub $network;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ledger_direct_xrpl_tx");
        delete_transient(XrplNetworkStub::cursorTransient());

        $this->network = new XrplNetworkStub();
        $this->network->install();
        ServiceFactory::setInstance(null);
    }

    protected function tearDown(): void
    {
        $this->network->remove();
        ServiceFactory::setInstance(null);
        delete_transient(XrplNetworkStub::cursorTransient());

        parent::tearDown();
    }

    public function testOnlyTheFirstSyncAfterAResetSendsAFailingRequest(): void
    {
        $sync = ServiceFactory::getInstance()->getSyncService();

        // Before the reset: a payment high up in the old epoch becomes the stored cursor.
        $this->network->addXrpPayment(1, '1000000', 'HASH-OLD-EPOCH', 99000000);
        $sync->syncTransactions(XrplNetworkStub::ACCOUNT, 'testnet');

        $this->network->reset(6000);
        $this->network->addXrpPayment(2, '1000000', 'HASH-NEW-1', 5000);

        $sync->syncTransactions(XrplNetworkStub::ACCOUNT, 'testnet');
        self::assertSame(1, $this->network->invalidCursorRequests);

        // Later syncs continue from the remembered ledger instead of failing again.
        $this->network->addXrpPayment(3, '1000000', 'HASH-NEW-2', 5100);
        $sync->syncTransactions(XrplNetworkStub::ACCOUNT, 'testnet');
        $sync->syncTransactions(XrplNetworkStub::ACCOUNT, 'testnet');
        self::assertSame(1, $this->network->invalidCursorRequests);

        global $wpdb;
        $hashes = $wpdb->get_col("SELECT hash FROM {$wpdb->prefix}ledger_direct_xrpl_tx ORDER BY hash");
        self::assertSame(['HASH-NEW-1', 'HASH-NEW-2', 'HASH-OLD-EPOCH'], $hashes);
        self::assertNotFalse(get_transient(XrplNetworkStub::cursorTransient()), 'The reset is remembered in the rate cache of the factory.');
    }
}
