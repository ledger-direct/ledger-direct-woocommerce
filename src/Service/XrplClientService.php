<?php

namespace Hardcastle\LedgerDirect\Service;

use LedgerDirect\Service\ConfigurationService;
use XRPL_PHP\Client\JsonRpcClient;
use XRPL_PHP\Core\Networks;
use XRPL_PHP\Models\Account\AccountTxRequest;

class XrplClientService
{
    public static self|null $_instance = null;

    private ConfigurationService $configurationService;

    private JsonRpcClient $client;

    public static function instance(): self
    {
        if (self::$_instance == null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function __construct()
    {
        $this->_initClient();
    }

    public function fetchAccountTransactions(string $address, ?int $lastLedgerIndex): array
    {
        $req = new AccountTxRequest($address, $lastLedgerIndex);
        $res = $this->client->syncRequest($req);

        return $res->getResult()['transactions'];
    }

    public function getNetwork(): array
    {
        /*
        if(!$this->configurationService->isTest()) {
            return Networks::getNetwork('mainnet');
        }
        */

        return Networks::getNetwork('testnet');
    }

    private function _initClient(): void
    {
        $jsonRpcUrl = $this->getNetwork()['jsonRpcUrl'];
        $this->client = new JsonRpcClient($jsonRpcUrl);
    }
}