<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;
use Hardcastle\LedgerDirect\Xrpl\Networks;
use Hardcastle\LedgerDirect\Xrpl\XrplJsonRpcClient;
use LedgerDirect;

class XrplClientService
{
    public static self|null $_instance = null;

    private ConfigurationService $configurationService;

    private XrplJsonRpcClient $client;

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
     * @param array|null $marker
     * @return array
     * @throws Exception
     */
    public function fetchAccountTransactions(string $address, ?int $lastLedgerIndex, ?array $marker = null): array
    {
        $this->initClient();

        try {
            return $this->client->accountTx($address, $lastLedgerIndex, $marker);
        } catch (Exception $exception) {
            LedgerDirect::log('Error fetching account transactions: ' . $exception->getMessage(), 'error');
            return []; // Return an empty array on error
        }
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
            return Networks::get('mainnet');
        }

        return Networks::get('testnet');
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
            $this->client = new XrplJsonRpcClient($jsonRpcUrl);
        }
    }
}