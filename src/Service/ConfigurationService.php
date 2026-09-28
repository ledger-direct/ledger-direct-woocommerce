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

    /** The payment page's look: logo, accent colour, and the public identifiers of the wallet apps. */
    public const CONFIG_KEY_PAGE_LOGO_MODE = 'xrpl_page_logo_mode';
    public const CONFIG_KEY_PAGE_LOGO = 'xrpl_page_logo';
    public const CONFIG_KEY_PAGE_ACCENT = 'xrpl_page_accent';
    public const CONFIG_KEY_XAMAN_API_KEY = 'xrpl_xaman_api_key';
    public const CONFIG_KEY_WALLETCONNECT_PROJECT_ID = 'xrpl_walletconnect_project_id';

    public const LOGO_MODE_SHOP = 'shop';
    public const LOGO_MODE_CUSTOM = 'custom';
    public const LOGO_MODE_NONE = 'none';
    public const LOGO_MODES = [self::LOGO_MODE_SHOP, self::LOGO_MODE_CUSTOM, self::LOGO_MODE_NONE];

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
     * Which logo the payment page shows: shop, custom or none.
     */
    public function getPaymentPageLogoMode(): string
    {
        $mode = (string) $this->getOrDefault(self::CONFIG_KEY_PAGE_LOGO_MODE, self::LOGO_MODE_SHOP);

        return in_array($mode, self::LOGO_MODES, true) ? $mode : self::LOGO_MODE_SHOP;
    }

    /**
     * The uploaded logo as a media library attachment id, 0 when none.
     */
    public function getPaymentPageLogoAttachmentId(): int
    {
        return (int) $this->getOrDefault(self::CONFIG_KEY_PAGE_LOGO, 0);
    }

    /**
     * The accent colour as stored; the core's AccentColor decides whether the page uses it.
     */
    public function getPaymentPageAccentColor(): string
    {
        return trim((string) $this->getOrDefault(self::CONFIG_KEY_PAGE_ACCENT, '#1f5eff'));
    }

    /**
     * Xaman's public API key; empty means the "open in wallet app" button is not offered.
     */
    public function getXamanApiKey(): string
    {
        return trim((string) $this->getOrDefault(self::CONFIG_KEY_XAMAN_API_KEY, ''));
    }

    /**
     * The WalletConnect project id; empty means it is not offered.
     */
    public function getWalletConnectProjectId(): string
    {
        return trim((string) $this->getOrDefault(self::CONFIG_KEY_WALLETCONNECT_PROJECT_ID, ''));
    }

    private function getOrDefault(string $configIdentifier, mixed $default): mixed
    {
        try {
            return $this->get($configIdentifier, $default);
        } catch (Exception $exception) {
            return $default;
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