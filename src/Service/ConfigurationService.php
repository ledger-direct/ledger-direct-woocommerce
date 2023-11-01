<?php

namespace Hardcastle\LedgerDirect\Service;

use Exception;
use WC_Order;
use function XRPL_PHP\Sugar\dropsToXrp;

class ConfigurationService
{
    private const CONFIG_DOMAIN = 'LedgerDirect';

    private const CONFIG_KEY_MAINNET_ACCOUNT = 'xrplMainnetAccount';

    private const CONFIG_KEY_MAINNET_TOKEN_NAME = 'xrplMainnetCustomTokenName';

    private const CONFIG_KEY_MAINNET_TOKEN_ISSUER = 'xrplMainnetCustomTokenIssuer';

    private const CONFIG_KEY_TESTNET_ACCOUNT = 'xrpl_testnet_account';

    private const CONFIG_KEY_TESTNET_TOKEN_NAME = 'xrplTestsnetCustomTokenName';

    private const CONFIG_KEY_TESTNET_TOKEN_ISSUER = 'xrplTestnetCustomTokenIssuer';
    private const WP_OPTION_NAME = 'woocommerce_ledger-direct_settings';

    private array $config = [];

    public function __construct() {
        global $wpdb;

        $statement = $wpdb->prepare(
            "SELECT option_value AS settings FROM {$wpdb->prefix}options WHERE option_name = %s",
            [self::WP_OPTION_NAME]
        );
        $settings_raw = $wpdb->get_col($statement)[0] ?? null;

        $this->config = unserialize($settings_raw);
    }

    /**
     *
     *
     * @param string $configIdentifier
     * @return mixed
     * @throws Exception
     */
    public function get(string $configIdentifier): mixed
    {
        $value = $this->config[$configIdentifier] ?? null;
        if (empty($value)) {
            throw new Exception('LedgerDirect: Config value "' . $configIdentifier . '" not found.');
        }

        return $value;
    }

    public function isTest(): bool
    {
        return $this->get('xrpl_network');
    }

    public function getDestinationAccount(): string
    {
        if ($this->isTest()) {
            return $this->get(self::CONFIG_KEY_TESTNET_ACCOUNT);
        }

        return $this->get(self::CONFIG_KEY_MAINNET_ACCOUNT);
    }


}