<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;

class ConfigurationService
{
    public const CONFIG_DOMAIN = 'ledger-direct';

    public const CONFIG_KEY_NETWORK = 'xrpl_network';

    public const CONFIG_KEY_MAINNET_ACCOUNT = 'xrpl_mainnet_destination_account';

    public const CONFIG_KEY_TESTNET_ACCOUNT = 'xrpl_testnet_destination_account';

    public const CONFIG_KEY_IS_RLUSD_ENABLED = 'xrpl_is_rlusd_enabled';

    public const CONFIG_KEY_IS_USDC_ENABLED = 'xrpl_is_usdc_enabled';

    public const CONFIG_KEY_PAYMENT_PAGE_TITLE = 'xrpl_payment_page_title';

    public const CONFIG_KEY_EXPIRY = 'xrpl_quote_expiry';

    private LedgerDirectPaymentGateway $gateway;

    protected array $config;

    public function __construct() {

    }

    /**
     * Get config value using the WooCommerce gateway method.
     *
     * @param string $configIdentifier
     * @param mixed|null $default
     * @return mixed
     * @throws Exception
     */
    public function get(string $configIdentifier, mixed $default = null): mixed
    {
        $this->gateway = LedgerDirectPaymentGateway::instance();
        $value = $this->gateway->get_option($configIdentifier, $default);

        if (is_null($value)) {
            throw new Exception('LedgerDirect: Config value "' . esc_html($configIdentifier) . '" not found.');
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
        return $this->get(self::CONFIG_KEY_NETWORK, 'testnet') === 'testnet';
    }

    /**
     * Get the XRPL network type.
     *
     * @return string
     * @throws Exception
     */
    public function getNetwork(): string
    {
        return $this->get(self::CONFIG_KEY_NETWORK);
    }

    /**
     * Get the destination account based on the selected network.
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
     * Get the custom title for the payment page.
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

    /**
     * Check if RLUSD payment is enabled.
     *
     * @return bool
     * @throws Exception
     */
    public function isRlusdEnabled(): bool
    {
        if (!empty($this->getDestinationAccount())) {
            return $this->get(self::CONFIG_KEY_IS_RLUSD_ENABLED, 'no' ) === 'yes';
        }

        return false;
    }

    /**
     * Check if RLUSD payment is enabled. If no destination account is set, it will always return false.
     *
     * @return bool
     * @throws Exception
     */
    public function isUsdcEnabled(): bool
    {
        if (!empty($this->getDestinationAccount())) {
            return $this->get(self::CONFIG_KEY_IS_USDC_ENABLED, 'no' ) === 'yes';
        }

        return false;
    }

}