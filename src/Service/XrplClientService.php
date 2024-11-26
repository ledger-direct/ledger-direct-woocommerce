<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Hardcastle\XRPL_PHP\Client\JsonRpcClient;
use Hardcastle\XRPL_PHP\Core\Networks;
use Hardcastle\XRPL_PHP\Models\Account\AccountTxRequest;

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
        $this->_initClient();
    }

    /**
     *
     *
     * @param string $address
     * @param int|null $lastLedgerIndex
     * @return array
     * @throws GuzzleException
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