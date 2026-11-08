<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Port\WpdbXrplTransactionRepository;

/**
 * The repository on Xahau's tables, which the installer creates alongside the XRP Ledger's.
 * Nothing is shared: a row stored here is not in the XRPL table, and the tag counters are
 * kept apart even for one and the same address.
 */
class WpdbXahauTransactionRepositoryTest extends TestCase
{
    private const ACCOUNT = 'rL7DjHoSvkn8TXYPcv6sBsJRwqdzAc6VxK';

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        foreach (['xrpl', 'xahau'] as $chain) {
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ledger_direct_{$chain}_tx");
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ledger_direct_{$chain}_destination_tag");
        }
    }

    public function testTheChainsShareNoRowAndNoCounter(): void
    {
        global $wpdb;
        $xahau = new WpdbXrplTransactionRepository('XAHAU');
        $xrpl = new WpdbXrplTransactionRepository('XRPL');

        $xahau->saveTransactions([new XrplTransaction(
            network: 'testnet', ledgerIndex: '20180000', hash: 'XAHAU-HASH', ctid: 'C000000000000000',
            account: 'rSender', destination: self::ACCOUNT, destinationTag: 7, date: 0, meta: ['delivered_amount' => '1'], tx: [],
        )]);

        $this->assertSame(['XAHAU-HASH'], $xahau->findExistingHashes(['XAHAU-HASH']));
        $this->assertSame([], $xrpl->findExistingHashes(['XAHAU-HASH']), 'the XRPL table has no Xahau row');
        $this->assertCount(1, $xahau->findTransactions(self::ACCOUNT, 7));
        $this->assertSame([], $xrpl->findTransactions(self::ACCOUNT, 7));
        $this->assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ledger_direct_xahau_tx"));
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ledger_direct_xrpl_tx"));
        $this->assertSame('20180000', $xahau->getLastSyncedLedgerIndex(self::ACCOUNT, 'testnet'));
        $this->assertNull($xrpl->getLastSyncedLedgerIndex(self::ACCOUNT, 'testnet'));

        $first = $xahau->nextDestinationTagSequence(self::ACCOUNT);
        $this->assertSame($first + 1, $xahau->nextDestinationTagSequence(self::ACCOUNT));
        $this->assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ledger_direct_xahau_destination_tag"));
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ledger_direct_xrpl_destination_tag"), 'a counter of its own per chain');
    }

    public function testAChainWithoutTablesIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WpdbXrplTransactionRepository('STELLAR');
    }
}
