<?php

namespace Hardcastle\LedgerDirect\Service;

use Exception;

class ConfigurationService
{
    public const CONFIG_DOMAIN = 'ledger-direct';

    public const CONFIG_KEY_NETWORK = 'xrpl_network';

    public const CONFIG_KEY_MAINNET_ACCOUNT = 'xrpl_mainnet_destination_account';

    public const CONFIG_KEY_MAINNET_TOKEN_NAME = 'xrpl_mainnet_token_name';

    public const CONFIG_KEY_MAINNET_TOKEN_ISSUER = 'xrpl_mainnet_token_issuer';

    public const CONFIG_KEY_TESTNET_ACCOUNT = 'xrpl_testnet_destination_account';

    public const CONFIG_KEY_TESTNET_TOKEN_NAME = 'xrpl_testnet_token_name';

    public const CONFIG_KEY_TESTNET_TOKEN_ISSUER = 'xrpl_testnet_token_issuer';

    public const CONFIG_KEY_PAYMENT_PAGE_TITLE = 'xrpl_payment_page_title';

    public const CONFIG_KEY_EXPIRY = 'xrpl_quote_expiry';

    public const WP_OPTION_NAME = 'woocommerce_ledger-direct_settings';

    protected array $config;

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
     * @param mixed|null $default
     * @return mixed
     * @throws Exception
     */
    public function get(string $configIdentifier, mixed $default = null): mixed
    {
        $value = $this->config[$configIdentifier] ?? null;

        if (empty($value)) {
            if (!is_null($default)) {
                return $default;
            }
            throw new Exception('LedgerDirect: Config value "' . $configIdentifier . '" not found.');
        }

        return $value;
    }

    /**
     *
     *
     * @return bool
     * @throws Exception
     */
    public function isTest(): bool
    {
        return $this->get(self::CONFIG_KEY_NETWORK) !== 'Testnet';
    }

    /**
     *
     *
     * @return string
     * @throws Exception
     */
    public function getNetwork(): string
    {
        return $this->get(self::CONFIG_KEY_NETWORK);
    }

    /**
     *
     *
     * @return string
     * @throws Exception
     */
    public function getDestinationAccount(): string
    {
        if ($this->isTest()) {
            return $this->get(self::CONFIG_KEY_TESTNET_ACCOUNT);
        }

        return $this->get(self::CONFIG_KEY_MAINNET_ACCOUNT);
    }

    /**
     *
     *
     * @return string
     * @throws Exception
     */
    public function getTokenName(): string
    {
        if ($this->isTest()) {
            return $this->get(self::CONFIG_KEY_TESTNET_TOKEN_NAME);
        }

        return $this->get(self::CONFIG_KEY_MAINNET_TOKEN_NAME);
    }

    /**
     *
     *
     * @return string
     * @throws Exception
     */
    public function getIssuer(): string
    {
        if ($this->isTest()) {
            return $this->get(self::CONFIG_KEY_TESTNET_TOKEN_ISSUER);
        }

        return $this->get(self::CONFIG_KEY_MAINNET_TOKEN_ISSUER);
    }


    /**
     *
     *
     * @return string
     */
    public function getPaymentPageTitle(): string
    {
        try {
            return $this->get(self::CONFIG_KEY_PAYMENT_PAGE_TITLE);
        } catch (Exception $exception) {
            return '';
        }
    }

}