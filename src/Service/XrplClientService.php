<?php

namespace Hardcastle\LedgerDirect\Service;

use Exception;
use XRPL_PHP\Client\JsonRpcClient;
use XRPL_PHP\Core\Networks;
use XRPL_PHP\Models\Account\AccountTxRequest;

class XrplClientService
{
    public static self|null $_instance = null;

    private ConfigurationService $configurationService;

    private JsonRpcClient $client;

    public function __construct(ConfigurationService $configurationService)
    {
        $this->configurationService = $configurationService;
        $this->_initClient();
    }

    /**
     *
     *
     * @param string $address
     * @param int|null $lastLedgerIndex
     * @return array
     */
    public function fetchAccountTransactions(string $address, ?int $lastLedgerIndex): array
    {
        $req = new AccountTxRequest($address, $lastLedgerIndex);
        $res = $this->client->syncRequest($req);

        return $res->getResult()['transactions'];
    }

    /**
     *
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
     *
     *
     * @return void
     * @throws Exception
     */
    private function _initClient(): void
    {
        $jsonRpcUrl = $this->getNetwork()['jsonRpcUrl'];
        $this->client = new JsonRpcClient($jsonRpcUrl);
    }
}