<?php

namespace Hardcastle\LedgerDirect\Service;

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
     * @throws \Exception
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

        // TODO: Use NetworkId or CTID!
        $statement = $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}xrpl_tx WHERE destination = %s AND destination_tag = %d",
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
     *
     *
     * @param string $address
     * @return void
     */
    public function syncTransactions(string $address): void {
        global $wpdb;

        $statement = $wpdb->prepare("SELECT MAX(ledger_index) AS ledger_index FROM {$wpdb->prefix}xrpl_tx");
        $lastLedgerIndex = (int) $wpdb->get_col($statement)[0] ?? -1;

        $transactions = $this->clientService->fetchAccountTransactions($address, $lastLedgerIndex);

        if (count($transactions)) {
            $this->txToDb($transactions, $address);
        }

        // TODO: If marker is present, loop
    }

    /**
     *
     *
     * @param array $transactions
     * @param string $address
     * @return void
     */
    public function txToDb(array $transactions, string $address): void
    {
        global $wpdb;

        $transactions = $this->filterIncomingTransactions($transactions, $address);
        $transactions = $this->filterNewTransactions($transactions);

        $rows = $this->hydrateRows($transactions);

        foreach ($rows as $row) {
            $table = $wpdb->prefix . 'xrpl_tx';
            $format = ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'];
            $wpdb->insert($table, $row, $format);
        }
    }

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
     *
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
        $filler = implode(array_fill(0, count($hashes), '%s,'));

        $statement = $wpdb->prepare(
            "SELECT hash FROM {$wpdb->prefix}xrpl_tx WHERE hash IN (" . substr($filler, 0, -1) . ")",
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
     *
     *
     * @param array $transactions
     * @return array
     * @throws \Exception
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

            //TODO: Check ctid adoption, see XLS-37d
        }

        return $rows;
    }
}