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
     * See the port contract: one atomic statement (never select-then-update),
     * a fresh counter starts at a random offset in [0, 2^31-1] and never at 0,
     * and the counter table survives uninstall.
     *
     * LAST_INSERT_ID(expr) both stores and returns the new counter value,
     * per connection, so the value read back is this caller's own. $wpdb
     * holds one connection for the whole request. The stored counter is
     * 1-based while the port contract is 0-based, hence the -1 (and why
     * SEQUENCE_START_MAX is 2^31 rather than 2^31-1).
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
                    'network' => $transaction->network,
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
                ['%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
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

    /**
     * Port method: every transaction on this account and tag, newest first
     * (`ORDER BY ledger_index DESC`, tie-broken by primary key). Which of
     * them fulfills an intent is the core's decision
     * (SyncService::findTransactionFor()), so nothing else is filtered here.
     *
     * @return XrplTransaction[]
     */
    public function findTransactions(string $destination, int $destinationTag): array
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

    /**
     * Port method: the cursor is scoped per account and network; a global
     * MAX() would let one mainnet row pin the testnet cursor forever. Rows
     * whose network is unknown (empty, CTID unusable at migration time)
     * never match and are simply re-synced once.
     */
    public function getLastSyncedLedgerIndex(string $destinationAccount, string $network): ?string
    {
        global $wpdb;

        $table = self::table(self::TX_TABLE);

        $max = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT MAX(ledger_index) FROM {$table} WHERE destination = %s AND network = %s",
                $destinationAccount,
                $network
            )
        );

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
            network: (string) ($row['network'] ?? ''),
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
