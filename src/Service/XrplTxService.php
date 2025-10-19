<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Hardcastle\XRPL_PHP\Core\Ctid;

class XrplTxService
{
    public const DESTINATION_TAG_RANGE_MIN = 10000;

    public const DESTINATION_TAG_RANGE_MAX = 2140000000;


    public static self|null $_instance = null;

    private XrplClientService $clientService;

    public function __construct(XrplClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    /**
     * Generates a unique DestinationTag which is used to attach a payment request to a specific order.
     *
     * @see https://xrpl.org/source-and-destination-tags.html
     * @see https://xrpl.org/require-destination-tags.html
     *
     * @param string $account
     * @return int
     * @throws Exception
     */
    public function generateDestinationTag(string $account): int
    {
        global $wpdb;

        while (true) {
            $destinationTag = random_int(self::DESTINATION_TAG_RANGE_MIN, self::DESTINATION_TAG_RANGE_MAX);

            $statement = $wpdb->prepare(
                "SELECT destination_tag FROM {$wpdb->prefix}xrpl_destination_tag WHERE destination_tag = %d",
                [$destinationTag]
            );
            $matches = $wpdb->get_results($statement);

            if (empty($matches)) {
                $table = $wpdb->prefix . 'xrpl_destination_tag';
                $data = ['destination_tag' => $destinationTag, 'account' => $account];
                $format = ['%d','%s'];
                $wpdb->insert($table,$data,$format);

                return $destinationTag;
            }
        }
    }

    /**
     * Fetches a specific transaction from the database.
     *
     * @param string $destination
     * @param int $destinationTag
     * @return array|null
     */
    public function findTransaction(string $destination, int $destinationTag): array|null
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ledger_direct_xrpl_tx';
        $statement = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE destination = %s AND destination_tag = %d",
            [$destination, $destinationTag]
        );
        $matches = $wpdb->get_results($statement, ARRAY_A);

        if (!empty($matches)) {
            return $matches[0];
        }

        // TODO: If for whatever reason there are more than one matches, throw error

        return null;
    }

    /**
     * Synchronizes transactions for a given address with the XRPL.
     *
     * @param string $address
     * @return void
     * @throws GuzzleException
     */
    public function syncTransactions(string $address): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ledger_direct_xrpl_tx';
        $statement = $wpdb->prepare("SELECT MAX(ledger_index) AS ledger_index FROM {$table}");
        $result = $wpdb->get_col($statement);
        $lastLedgerIndex = isset($result[0]) ? (int) $result[0] : -1;

        while (true) {
            $result = $this->clientService->fetchAccountTransactions($address, $lastLedgerIndex);
            $transactions = $result['transactions'] ?? [];
            if (count($transactions)) {
                $this->txToDb($transactions, $address);
            }
            if (!isset($result['marker'])) {
                break;
            }
        }
    }

    /**
     * Inserts new transactions into the database.
     *
     * @param array $transactions
     * @param string $address
     * @return void
     * @throws Exception
     */
    public function txToDb(array $transactions, string $address): void
    {
        global $wpdb;

        $transactions = $this->filterIncomingTransactions($transactions, $address);
        $transactions = $this->filterNewTransactions($transactions);

        $rows = $this->hydrateRows($transactions);

        foreach ($rows as $row) {
            $table = $wpdb->prefix . 'ledger_direct_xrpl_tx';
            $format = ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'];
            $wpdb->insert($table, $row, $format);
        }
    }

    /**
     * Filters out transactions that are not incoming to the specified address.
     *
     * @param array $transactions
     * @param string $ownAddress
     * @return array
     */
    private function filterIncomingTransactions(array $transactions, string $ownAddress): array
    {
        foreach ($transactions as $key => $transaction) {
            if (!isset($transaction['tx']['Destination']) || $transaction['tx']['Destination'] !== $ownAddress) {
                unset($transactions[$key]);
            }
        }

        return $transactions;
    }

    /**
     * Filters out transactions that are already present in the database.
     *
     * @param array $transactions
     * @return array
     */
    private function filterNewTransactions(array $transactions): array
    {
        global $wpdb;

        $reducerFn = function ($hashes, $transaction) {
            $hashes[] = $transaction['tx']['hash'];

            return $hashes;
        };
        $hashes = array_reduce($transactions, $reducerFn, []);
        $hashes = array_map('esc_sql', $hashes);
        $placeholders = implode(',', array_fill(0, count($hashes), '%s'));

        $table = $wpdb->prefix . 'ledger_direct_xrpl_tx';
        $statement = $wpdb->prepare(
            "SELECT hash FROM {$table} WHERE hash IN (" . $placeholders . ")",
            $hashes
        );
        $matches = $wpdb->get_results($statement, ARRAY_A);

        $lookup = [];
        foreach ($matches as $match) {
            $lookup[] = $match['hash'];
        }

        foreach ($transactions as $key => $transaction) {
            if (in_array($transaction['tx']['hash'], $lookup, true)) {
                unset($transactions[$key]);
            }
        }

        return $transactions;
    }

    /**
     * Hydrates the transaction data into a format suitable for database insertion.
     *
     * @param array $transactions
     * @return array
     * @throws Exception
     */
    private function hydrateRows(array $transactions): array
    {
        $rows = [];

        foreach ($transactions as $key => $transaction) {

            $ledgerIndex = (int) $transaction['tx']['ledger_index'];
            $transactionIndex = (int) $transaction['meta']['TransactionIndex'];
            $networkId = $this->clientService->getNetwork()['networkId'];
            $ctid = Ctid::fromRawValues($ledgerIndex, $transactionIndex, $networkId)->getHex();

            $rows[] = [
                'ledger_index' => $transaction['tx']['ledger_index'],
                'hash' => $transaction['tx']['hash'],
                'ctid' => $ctid,
                'account' => $transaction['tx']['Account'],
                'destination' => $transaction['tx']['Destination'],
                'destination_tag' => $transaction['tx']['DestinationTag'] ?? null,
                'date' => $transaction['tx']['date'],
                'meta' => json_encode($transaction['meta']),
                'tx' => json_encode($transaction['tx'])
            ];
        }

        return $rows;
    }
}