<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Hardcastle\XRPL_PHP\Client\JsonRpcClient;
use Hardcastle\XRPL_PHP\Core\Networks;
use Hardcastle\XRPL_PHP\Models\Account\AccountTxRequest;
use LedgerDirect;

class XrplClientService
{
    public static self|null $_instance = null;

    private ConfigurationService $configurationService;

    private JsonRpcClient $client;

    /**
     * Constructor.
     *
     * @throws Exception
     */
    public function __construct(ConfigurationService $configurationService)
    {
        $this->configurationService = $configurationService;
    }

    /**
     * Fetches account transactions for a given address from the XRPL network.
     *
     * @param string $address
     * @param int|null $lastLedgerIndex
     * @return array
     * @throws GuzzleException
     * @throws Exception
     */
    public function fetchAccountTransactions(string $address, ?int $lastLedgerIndex): array
    {
        $this->initClient();

        $req = new AccountTxRequest($address, $lastLedgerIndex);
        $res = $this->client->syncRequest($req);

        if ($res->getStatus() === 'error') {
            LedgerDirect::log('Error fetching account transactions: ' . $res->getError(), 'error');
            return []; // Return an empty array on error
        }

        return $res->getResult()['transactions'];
    }

    /**
     * Determines the XRPL network configuration based on the current environment.
     *
     * @return array
     * @throws Exception
     */
    public function getNetwork(): array
    {
        if(!$this->configurationService->isTest()) {
            return Networks::getNetwork('mainnet');
        }

        return Networks::getNetwork('testnet');
    }

    /**
     * Initializes the JSON-RPC client with the appropriate network URL.
     *
     * @return void
     * @throws Exception
     */
    private function initClient(): void
    {
        if (!isset($this->client)) {
            $jsonRpcUrl = $this->getNetwork()['jsonRpcUrl'];
            $this->client = new JsonRpcClient($jsonRpcUrl);
        }
    }
}