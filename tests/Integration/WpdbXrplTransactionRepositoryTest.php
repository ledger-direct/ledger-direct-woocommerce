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

        $found = $this->repository->findTransactionsByTag(self::ACCOUNT, 5);

        $this->assertSame(['HASH-NEW', 'HASH-OLD'], array_map(static fn ($t) => $t->hash, $found));
        $this->assertSame([], $this->repository->findTransactionsByTag(self::ACCOUNT, 7));
    }

    public function testATagAboveTheSigned32BitRangeSurvivesStorageAndLookup(): void
    {
        $tag = 4294967295;

        $this->repository->saveTransactions([$this->transaction('HASH-BIG', $tag, '100')]);

        $found = $this->repository->findTransaction(self::ACCOUNT, $tag);

        $this->assertNotNull($found);
        $this->assertSame($tag, $found->destinationTag);
        $this->assertSame('HASH-BIG', $found->hash);
        $this->assertSame(['delivered_amount' => '1000000'], $found->meta);
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
        $this->assertNull($this->repository->getLastSyncedLedgerIndex());

        $this->repository->saveTransactions([
            $this->transaction('HASH-A', 1, '99999999'),
            $this->transaction('HASH-B', 2, '100000000'),
        ]);

        $this->assertSame('100000000', $this->repository->getLastSyncedLedgerIndex());
    }

    public function testFindTransactionReturnsNullWhenNothingMatches(): void
    {
        $this->assertNull($this->repository->findTransaction(self::ACCOUNT, 12345));
    }

    private function transaction(string $hash, int $destinationTag, string $ledgerIndex): XrplTransaction
    {
        return new XrplTransaction(
            ledgerIndex: $ledgerIndex,
            hash: $hash,
            ctid: 'C000000100000000',
            account: 'rSenderAccount',
            destination: self::ACCOUNT,
            destinationTag: $destinationTag,
            date: 1700000000,
            meta: ['delivered_amount' => '1000000'],
            tx: ['TransactionType' => 'Payment', 'hash' => $hash],
        );
    }
}
