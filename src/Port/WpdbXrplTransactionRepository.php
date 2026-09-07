<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Port;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use RuntimeException;

/**
 * Platform side of {@see XrplTransactionRepositoryInterface}, on $wpdb.
 * Storage primitives only - sync, dedup and tag derivation live in the core.
 *
 * Physical table names are the core's logical names behind $wpdb->prefix
 * (INVARIANTS.md, "Tables"); the schema itself is owned by
 * LedgerDirectInstall.
 */
class WpdbXrplTransactionRepository implements XrplTransactionRepositoryInterface
{
    private const TX_TABLE = 'ledger_direct_xrpl_tx';

    private const TAG_TABLE = 'ledger_direct_xrpl_destination_tag';

    /** Highest 1-based value a fresh counter may start at (2^31). */
    private const SEQUENCE_START_MAX = 2147483648;

    /**
     * One atomic statement, not a select-then-update: two checkouts hitting
     * the same account concurrently must never receive the same sequence
     * number, or two orders share a destination tag and the second payment
     * settles against the first order.
     *
     * LAST_INSERT_ID(expr) both stores and returns the new counter value,
     * per connection, so the value read back is this caller's own. $wpdb
     * holds one connection for the whole request.
     *
     * A fresh counter starts at a random offset rather than at 0. The core
     * derives tags from the sequence deterministically, so two databases
     * counting from 0 for the same receiving account - a second shop
     * platform on the same wallet, or a reinstall after uninstall - would
     * issue the very same tags and match each other's payments. A random
     * start in the lower half of the range leaves over two billion tags
     * before the core's exhaustion guard.
     *
     * The counter is 1-based while the port contract is 0-based, hence
     * the -1.
     */
    public function nextDestinationTagSequence(string $destinationAccount): int
    {
        global $wpdb;

        $table = self::table(self::TAG_TABLE);

        $result = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "INSERT INTO {$table} (destination_account, sequence)
                 VALUES (%s, LAST_INSERT_ID(%d))
                 ON DUPLICATE KEY UPDATE sequence = LAST_INSERT_ID(sequence + 1)",
                $destinationAccount,
                random_int(1, self::SEQUENCE_START_MAX)
            )
        );

        if ($result === false) {
            throw new RuntimeException('Could not reserve a destination tag sequence: ' . esc_html($wpdb->last_error));
        }

        return ((int) $wpdb->get_var('SELECT LAST_INSERT_ID()')) - 1;
    }

    /**
     * @param string[] $hashes
     * @return string[]
     */
    public function findExistingHashes(array $hashes): array
    {
        global $wpdb;

        if ($hashes === []) {
            return [];
        }

        $table = self::table(self::TX_TABLE);
        $placeholders = implode(',', array_fill(0, count($hashes), '%s'));

        $matches = $wpdb->get_col(
            $wpdb->prepare(
                // $placeholders is a fixed list of literal '%s' tokens, one per hash.
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                "SELECT hash FROM {$table} WHERE hash IN ({$placeholders})",
                array_values($hashes)
            )
        );

        return array_map('strval', $matches);
    }

    /**
     * @param XrplTransaction[] $transactions
     */
    public function saveTransactions(array $transactions): void
    {
        global $wpdb;

        $table = self::table(self::TX_TABLE);

        foreach ($transactions as $transaction) {
            $suppress = $wpdb->suppress_errors();

            $inserted = $wpdb->insert(
                $table,
                [
                    'ledger_index' => $transaction->ledgerIndex,
                    'hash' => $transaction->hash,
                    'ctid' => $transaction->ctid,
                    'account' => $transaction->account,
                    'destination' => $transaction->destination,
                    'destination_tag' => $transaction->destinationTag,
                    'date' => $transaction->date,
                    'meta' => wp_json_encode($transaction->meta),
                    'tx' => wp_json_encode($transaction->tx),
                ],
                ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
            );

            $wpdb->suppress_errors($suppress);

            if ($inserted === false) {
                if (str_contains($wpdb->last_error, 'Duplicate entry')) {
                    // The unique index on `hash` doing its job: a concurrent
                    // sync stored this transaction between the core's dedup
                    // check and this insert. The row is already there.
                    continue;
                }

                throw new RuntimeException('Could not store XRPL transaction ' . esc_html($transaction->hash) . ': ' . esc_html($wpdb->last_error));
            }
        }
    }

    public function findTransaction(string $destination, int $destinationTag): ?XrplTransaction
    {
        global $wpdb;

        $table = self::table(self::TX_TABLE);

        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$table} WHERE destination = %s AND destination_tag = %d ORDER BY id ASC LIMIT 1",
                $destination,
                $destinationTag
            ),
            ARRAY_A
        );

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Every synced transaction addressed to this account and tag, newest
     * ledger first. Not part of the core port: the port's findTransaction()
     * answers "the" transaction for a tag, but a tag can carry more than
     * one - a stray payment from before the order, a wrong-asset payment
     * followed by the right one - and the order service needs to choose.
     *
     * @return XrplTransaction[]
     */
    public function findTransactionsByTag(string $destination, int $destinationTag): array
    {
        global $wpdb;

        $table = self::table(self::TX_TABLE);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$table} WHERE destination = %s AND destination_tag = %d ORDER BY ledger_index DESC, id DESC",
                $destination,
                $destinationTag
            ),
            ARRAY_A
        );

        return array_map([self::class, 'hydrate'], is_array($rows) ? $rows : []);
    }

    public function getLastSyncedLedgerIndex(): ?string
    {
        global $wpdb;

        $table = self::table(self::TX_TABLE);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $max = $wpdb->get_var("SELECT MAX(ledger_index) FROM {$table}");

        return $max === null ? null : (string) $max;
    }

    public function truncate(): void
    {
        global $wpdb;

        $table = self::table(self::TX_TABLE);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("TRUNCATE TABLE {$table}");
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): XrplTransaction
    {
        return new XrplTransaction(
            ledgerIndex: (string) $row['ledger_index'],
            hash: (string) $row['hash'],
            ctid: (string) $row['ctid'],
            account: (string) $row['account'],
            destination: (string) $row['destination'],
            destinationTag: $row['destination_tag'] === null ? null : (int) $row['destination_tag'],
            date: (int) $row['date'],
            meta: self::decodeJsonColumn($row['meta'] ?? null),
            tx: self::decodeJsonColumn($row['tx'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonColumn(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function table(string $baseName): string
    {
        global $wpdb;

        return $wpdb->prefix . $baseName;
    }
}
