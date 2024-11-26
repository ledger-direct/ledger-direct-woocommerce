<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Psr\Log\LoggerInterface;

use Mockery;

class ConfigurationServiceMock extends ConfigurationService
{
    public function __construct()
    {
        //parent::__construct();

        $this->config = [
            'xrpl_network' => 'testnet',
            'xrpl_testnet_destination_account' => 'rL7DjHoSvkn8TXYPcv6sBsJRwqdzAc6VxK',
            'xrpl_mainnet_destination_account' => null,
            'enabled' => 'yes',
            'xrpl_payment_page_title' => 'LedgerDirect Payment Page',
            'xrpl_quote_expiry' => 15,
            'xrpl_testnet_token_name' => 'LPT',
            'xrpl_testnet_token_issuer' => 'rpEvFBbceea6Ze4xEcMiL9smCT4pprXPU2',
            'xrpl_mainnet_token_name' => null,
            'xrpl_mainnet_token_issuer' => null
        ];

    }
    public static function createInstance(): ConfigurationService
    {
        return new self();
    }

    public static function createMock(): ConfigurationService
    {
        return Mockery::mock(ConfigurationService::class);
    }
}