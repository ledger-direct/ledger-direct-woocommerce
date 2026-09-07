<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Port\WpdbXrplTransactionRepository;

class WpdbXrplTransactionRepositoryTest extends TestCase
{
    private const ACCOUNT = 'rL7DjHoSvkn8TXYPcv6sBsJRwqdzAc6VxK';

    private WpdbXrplTransactionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ledger_direct_xrpl_tx");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ledger_direct_xrpl_destination_tag");

        $this->repository = new WpdbXrplTransactionRepository();
    }

    public function testSequenceStartsAtARandomOffsetAndIncrementsPerAccount(): void
    {
        $first = $this->repository->nextDestinationTagSequence(self::ACCOUNT);

        $this->assertGreaterThanOrEqual(0, $first);
        $this->assertLessThan(2147483648, $first);
        $this->assertSame($first + 1, $this->repository->nextDestinationTagSequence(self::ACCOUNT));
        $this->assertSame($first + 2, $this->repository->nextDestinationTagSequence(self::ACCOUNT));

        // A different account has its own counter, with its own start.
        $other = $this->repository->nextDestinationTagSequence('rAnotherAccount');
        $this->assertNotSame($first, $other);
        $this->assertSame($first + 3, $this->repository->nextDestinationTagSequence(self::ACCOUNT));
    }

    public function testFindTransactionsByTagReturnsNewestFirst(): void
    {
        $this->repository->saveTransactions([
            $this->transaction('HASH-OLD', 5, '100'),
            $this->transaction('HASH-NEW', 5, '200'),
            $this->transaction('HASH-OTHER-TAG', 6, '300'),
        ]);

        $found = $this->repository->findTransactions(self::ACCOUNT, 5);

        $this->assertSame(['HASH-NEW', 'HASH-OLD'], array_map(static fn ($t) => $t->hash, $found));
        $this->assertSame([], $this->repository->findTransactions(self::ACCOUNT, 7));
    }

    public function testATagAboveTheSigned32BitRangeSurvivesStorageAndLookup(): void
    {
        $tag = 4294967295;

        $this->repository->saveTransactions([$this->transaction('HASH-BIG', $tag, '100')]);

        $found = $this->repository->findTransactions(self::ACCOUNT, $tag)[0] ?? null;

        $this->assertNotNull($found);
        $this->assertSame($tag, $found->destinationTag);
        $this->assertSame('HASH-BIG', $found->hash);
        $this->assertSame(['delivered_amount' => '1000000'], $found->meta);
        $this->assertSame('testnet', $found->network);
        $this->assertSame(1.0, $found->getDeliveredAmount());
    }

    public function testDuplicateHashIsIgnoredNotDuplicated(): void
    {
        global $wpdb;

        $this->repository->saveTransactions([$this->transaction('HASH-A', 1, '100')]);
        $this->repository->saveTransactions([$this->transaction('HASH-A', 1, '101')]);

        $this->assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ledger_direct_xrpl_tx"));
    }

    public function testFindExistingHashesReturnsOnlyStoredOnes(): void
    {
        $this->repository->saveTransactions([
            $this->transaction('HASH-A', 1, '100'),
            $this->transaction('HASH-B', 2, '101'),
        ]);

        $this->assertSame([], $this->repository->findExistingHashes([]));
        $this->assertEqualsCanonicalizing(['HASH-A', 'HASH-B'], $this->repository->findExistingHashes(['HASH-A', 'HASH-B', 'HASH-C']));
    }

    public function testLastSyncedLedgerIndexIsNumericNotLexicographic(): void
    {
        $this->assertNull($this->repository->getLastSyncedLedgerIndex(self::ACCOUNT, 'testnet'));

        $this->repository->saveTransactions([
            $this->transaction('HASH-A', 1, '99999999'),
            $this->transaction('HASH-B', 2, '100000000'),
        ]);

        $this->assertSame('100000000', $this->repository->getLastSyncedLedgerIndex(self::ACCOUNT, 'testnet'));
    }

    /**
     * A ledger index only means anything within one network: a mainnet row
     * must never pin the testnet cursor, and another account's rows must
     * never advance this account's cursor.
     */
    public function testLastSyncedLedgerIndexIsScopedPerAccountAndNetwork(): void
    {
        $this->repository->saveTransactions([
            $this->transaction('HASH-TEST', 1, '20000000', 'testnet'),
            $this->transaction('HASH-MAIN', 2, '100000000', 'mainnet'),
            $this->transaction('HASH-OTHER', 3, '30000000', 'testnet', 'rAnotherAccount'),
        ]);

        $this->assertSame('20000000', $this->repository->getLastSyncedLedgerIndex(self::ACCOUNT, 'testnet'));
        $this->assertSame('100000000', $this->repository->getLastSyncedLedgerIndex(self::ACCOUNT, 'mainnet'));
        $this->assertSame('30000000', $this->repository->getLastSyncedLedgerIndex('rAnotherAccount', 'testnet'));
        $this->assertNull($this->repository->getLastSyncedLedgerIndex('rAnotherAccount', 'mainnet'));
    }

    public function testFindTransactionsReturnsEmptyWhenNothingMatches(): void
    {
        $this->assertSame([], $this->repository->findTransactions(self::ACCOUNT, 12345));
    }

    private function transaction(string $hash, int $destinationTag, string $ledgerIndex, string $network = 'testnet', string $destination = self::ACCOUNT): XrplTransaction
    {
        return new XrplTransaction(
            network: $network,
            ledgerIndex: $ledgerIndex,
            hash: $hash,
            ctid: 'C000000100000000',
            account: 'rSenderAccount',
            destination: $destination,
            destinationTag: $destinationTag,
            date: 1700000000,
            meta: ['delivered_amount' => '1000000'],
            tx: ['TransactionType' => 'Payment', 'hash' => $hash],
        );
    }
}
