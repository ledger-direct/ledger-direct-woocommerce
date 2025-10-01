<?php

namespace Hardcastle\LedgerDirect\Service;

use DI\DependencyException;
use DI\NotFoundException;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Provider\RlusdPriceProvider;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;
use Hardcastle\XRPL_PHP\Core\Stablecoin\RLUSD;
use LedgerDirect;
use WC_Order;
use function Hardcastle\XRPL_PHP\Sugar\dropsToXrp;

class OrderTransactionService
{
    public const METADATA_VERSION = 1.0;
    public const DEFAULT_EXPIRY = 60 * 15; // 15 minutes

    public static self|null $_instance = null;

    private ConfigurationService $configurationService;

    private XrplTxService $xrplTxService;

    private CryptoPriceProviderInterface $priceProvider;

    public function __construct(
        ConfigurationService         $configurationService,
        XrplTxService                $xrplTxService,
        CryptoPriceProviderInterface $priceProvider
    )
    {
        $this->configurationService = $configurationService;
        $this->xrplTxService = $xrplTxService;
        $this->priceProvider = $priceProvider;
    }

    /**
     * Get Crypto price for Order
     *
     * @param WC_Order $order
     * @param $cryptoCode
     * @param string|null $network
     * @return array
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getCryptoPriceForOrder(WC_Order $order, $cryptoCode, ?string $network = null): array
    {
        $orderTotal = $order->get_total();
        $currency = $order->get_currency();

        if($cryptoCode === 'XRP') {
            $exchangeRate = $this->priceProvider->getCurrentExchangeRate($currency);
            if (!$exchangeRate || $exchangeRate <= 0) {
                throw new Exception('Invalid exchange rate retrieved for currency: ' . esc_html($currency));
            }
            $amountRequested = round($orderTotal / $exchangeRate, 2, PHP_ROUND_HALF_UP);
        } elseif ($cryptoCode === 'RLUSD') {
            $container = ld_get_dependency_injection_container();
            $priceProvider = $container->get(RlusdPriceProvider::class);
            $exchangeRate = $priceProvider->getCurrentExchangeRate($currency);
            $amountRequested = RLUSD::getAmount(
                $network,
                ld_round_stable_coin($orderTotal / $exchangeRate)
            );
        } else {
            throw new Exception('Unsupported crypto code: ' . esc_html($cryptoCode));
        }

        return [
            'pairing' => $cryptoCode . '/' . $currency,
            'exchange_rate' => $exchangeRate,
            'amount_requested' => $amountRequested
        ];
    }

    /**
     * Return expiry timestamp (in seconds)
     *
     * @return int
     * @throws Exception
     */
    public function getExpiryTimestamp(): int
    {
        $minutes = $this->configurationService->get('xrpl_quote_expiry', self::DEFAULT_EXPIRY);

        return time() + (60 * $minutes);
    }

    /**
     * Checks if a quote is expired
     *
     * @param WC_Order $order
     * @return bool
     */
    public function isExpired(WC_Order $order): bool
    {
        $xrpl_order_meta = $order->get_meta(LedgerDirect::META_KEY);
        $expiry = $xrpl_order_meta['expiry'];
        $now = time();

        return $now > $expiry;
    }

    /**
     * Get initial order metadata for XRPL payments
     *
     * @param WC_Order $order
     * @param string $paymentMethod
     * @return void
     * @throws Exception
     */
    public function prepareOrderForXrpl(WC_Order $order, string $paymentMethod): void
    {
        $network = $this->configurationService->get('xrpl_network');
        $destination = ($network === 'mainnet') ? $this->configurationService->get('xrpl_mainnet_destination_account') : $this->configurationService->get('xrpl_testnet_destination_account');
        $destinationTag = $this->xrplTxService->generateDestinationTag($destination);

        $xrplData = [
            'chain' => 'XRPL',
            'network' => $network,
            'version' => self::METADATA_VERSION,
            'destination_account' => $destination,
            'destination_tag' => $destinationTag,
            'expiry' => $this->getExpiryTimestamp()
        ];

        $this->addAdditionalDataToPayment($order, $xrplData);

        match ($paymentMethod) {
            LedgerDirectPaymentGateway::XRP_PAYMENT_ID => $this->prepareXrpPayment($order),
            //LedgerDirectPaymentGateway::TOKEN_PAYMENT_ID => $this->prepareTokenPayment($order),
            LedgerDirectPaymentGateway::RLUSD_PAYMENT_ID => $this->prepareRlusdPayment($order, $network),
        };
    }

    /**
     * Prepare XRP payment data for the order
     *
     * @param WC_Order $order
     * @return void
     * @throws Exception
     */
    private function prepareXrpPayment(WC_Order $order): void
    {
        $additionalData = $this->getCryptoPriceForOrder($order, 'XRP');
        $additionalData['type'] = LedgerDirectPaymentGateway::XRP_PAYMENT_ID;

        $this->addAdditionalDataToPayment($order, $additionalData);
    }

    /**
     * Prepare RLUSD payment data for the order
     *
     * @param WC_Order $order
     * @param string $network
     * @return void
     * @throws Exception
     */
    private function prepareRlusdPayment(WC_Order $order, $network): void
    {
        if (!$this->configurationService->isRlusdEnabled()) {
            throw new Exception('RLUSD payments are not enabled in the configuration.');
        }
        $additionalData = $this->getCryptoPriceForOrder($order, 'RLUSD', $network);
        $additionalData['type'] = LedgerDirectPaymentGateway::RLUSD_PAYMENT_ID;
        $additionalData['currency'] = 'RLUSD';

        $this->addAdditionalDataToPayment($order, $additionalData);
    }

    /**
     * Prepare token payment data for the order
     *
     * @param WC_Order $order
     * @return void
     * @throws Exception
     */
    private function prepareTokenPayment(WC_Order $order): void
    {
        $issuer = $this->configurationService->getIssuer();
        $tokenName = $this->configurationService->getTokenName();
        $total = calculate_token_order_total($order, $tokenName);

        $additionalData = [
            'type' => LedgerDirectPaymentGateway::TOKEN_PAYMENT_ID,
            'issuer' => $issuer,
            'currency' => $tokenName,
            'value' => $total // $order->get_total(),
        ];

        $this->addAdditionalDataToPayment($order, $additionalData);
    }

    /**
     * Add additional data to order payment
     *
     * @param WC_Order $order
     * @param array $xrplCustomFields
     * @return void
     */
    private function addAdditionalDataToPayment(WC_Order $order, array $xrplCustomFields): void
    {
        $xrpl_order_meta = is_array($order->get_meta(LedgerDirect::META_KEY)) ? $order->get_meta(LedgerDirect::META_KEY) : [];
        $new_order_meta = array_replace_recursive($xrpl_order_meta, $xrplCustomFields);
        $order->update_meta_data(LedgerDirect::META_KEY, $new_order_meta);
        $order->save();
    }

    /**
     * Checks if the order has a valid XRPL payment transaction.
     *
     * @param WC_Order $order
     * @return bool
     */
    public function checkPayment(WC_Order $order): bool
    {
        $xrpl_order_meta = is_array($order->get_meta(LedgerDirect::META_KEY)) ? $order->get_meta(LedgerDirect::META_KEY) : [];

        return (isset($xrpl_order_meta['hash']) && isset($xrpl_order_meta['ctid']));
    }

    /**
     * Syncs the order transaction with XRPL and updates the order metadata.
     *
     * @param WC_Order $order
     * @return array|null
     * @throws Exception|GuzzleException
     */
    public function syncOrderTransactionWithXrpl(WC_Order $order): array|null
    {
        $xrpl_order_meta = $order->get_meta(LedgerDirect::META_KEY);

        if (isset($xrpl_order_meta['destination_account']) && isset($xrpl_order_meta['destination_tag'])) {
            $this->xrplTxService->syncTransactions($xrpl_order_meta['destination_account']);

            $tx = $this->xrplTxService->findTransaction(
                $xrpl_order_meta['destination_account'],
                (int)$xrpl_order_meta['destination_tag']
            );

            if ($tx) {
                $txMeta = json_decode($tx['meta'], true);

                if (is_array($txMeta['delivered_amount'])) {
                    $amount = $txMeta['delivered_amount'];
                } else {
                    $amount = dropsToXrp($txMeta['delivered_amount']);
                }

                $tx_order_meta = [
                    'hash' => $tx['hash'],
                    'ctid' => $tx['ctid'],
                    'delivered_amount' => $amount
                ];

                $new_order_meta = array_replace_recursive($xrpl_order_meta, $tx_order_meta);
                $order->update_meta_data(LedgerDirect::META_KEY, $new_order_meta);
                $order->save();

                return $tx;
            }
        }

        return null;
    }
}