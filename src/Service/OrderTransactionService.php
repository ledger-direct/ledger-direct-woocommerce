<?php

namespace Hardcastle\LedgerDirect\Service;

use Exception;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;
use WC_Order;
use function XRPL_PHP\Sugar\dropsToXrp;

class OrderTransactionService
{
    public const DEFAULT_EXPIRY = 60 * 15; // 15 minutes

    public static self|null $_instance = null;

    private ConfigurationService $configurationService;

    private XrplTxService $xrplTxService;

    private CryptoPriceProviderInterface $priceProvider;

    public function __construct(
        ConfigurationService $configurationService,
        XrplTxService $xrplTxService,
        CryptoPriceProviderInterface $priceProvider
    ) {
        $this->configurationService = $configurationService;
        $this->xrplTxService = $xrplTxService;
        $this->priceProvider = $priceProvider;
    }

    /**
     * Get XRP price for Order
     *
     * @param WC_Order $order
     * @return array
     * @throws Exception
     */
    public function getCurrentXrpPriceForOrder(WC_Order $order): array {
        $orderTotal = $order->get_total();
        $currency = $order->get_currency();
        $xrpUnitPrice = $this->priceProvider->getCurrentExchangeRate($currency);
        return [
            'pairing' => 'XRP/' . $currency,
            'exchange_rate' => $xrpUnitPrice,
            'amount_requested' => $orderTotal / $xrpUnitPrice
        ];
    }

    /**
     * Return expiry timestamp (in seconds)
     *
     * @return int
     * @throws Exception
     */
    public function getExpiryTimestamp(): int {
        $minutes = $this->configurationService->get('xrpl_quote_expiry', self::DEFAULT_EXPIRY);

        return time() + (60 * $minutes);
    }

    /**
     * Checks if a quote is expired
     *
     * @param WC_Order $order
     * @return bool
     */
    public function isExpired(WC_Order $order): bool {
        $xrpl_order_meta = $order->get_meta('xrpl');
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
    public function prepareOrderForXrpl(WC_Order $order, string $paymentMethod): void {
        $network = $this->configurationService->get('xrpl_network');
        $destination = ($network === 'mainnet') ? $this->configurationService->get('xrpl_mainnet_destination_account') : $this->configurationService->get('xrpl_testnet_destination_account');
        $destinationTag = $this->xrplTxService->generateDestinationTag($destination);

        $xrplData = [
            'network' => $network,
            'destination_account' => $destination,
            'destination_tag' => $destinationTag,
            'expiry' => $this->getExpiryTimestamp()
        ];

        $this->addAdditionalDataToPayment($order, $xrplData);

        match ($paymentMethod) {
            LedgerDirectPaymentGateway::XRP_PAYMENT_ID => $this->prepareXrpPayment($order),
            LedgerDirectPaymentGateway::TOKEN_PAYMENT_ID => $this->prepareTokenPayment($order),
        };
    }

    private function prepareXrpPayment(WC_Order $order): void
    {
        $additionalData = $this->getCurrentXrpPriceForOrder($order);
        $additionalData['type'] = LedgerDirectPaymentGateway::XRP_PAYMENT_TYPE;

        $this->addAdditionalDataToPayment($order, $additionalData);
    }

    private function prepareTokenPayment(WC_Order $order): void
    {
        $issuer = $this->configurationService->getIssuer();
        $tokenName = $this->configurationService->getTokenName();
        $total = calculate_token_order_total($order, $tokenName);

        $additionalData = [
            'type' => LedgerDirectPaymentGateway::TOKEN_PAYMENT_TYPE,
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
    private function addAdditionalDataToPayment(WC_Order $order, array $xrplCustomFields): void {
        $xrpl_order_meta = is_array($order->get_meta('xrpl')) ? $order->get_meta('xrpl') : [];
        $new_order_meta = array_replace_recursive($xrpl_order_meta, $xrplCustomFields);
        $order->update_meta_data( 'xrpl', $new_order_meta );
        $order->save();
    }

    /**
     *
     *
     * @param WC_Order $order
     * @return bool
     */
    public function checkPayment(WC_Order $order): bool {
        $xrpl_order_meta = is_array($order->get_meta('xrpl')) ? $order->get_meta('xrpl') : [];

        return (isset($xrpl_order_meta['hash']) && isset($xrpl_order_meta['ctid']));
    }

    /**
     *
     *
     * @param WC_Order $order
     * @return array|null
     * @throws Exception
     */
    public function syncOrderTransactionWithXrpl(WC_Order $order): array|null {
        $xrpl_order_meta = $order->get_meta('xrpl');

        if (isset($xrpl_order_meta['destination_account']) && isset($xrpl_order_meta['destination_tag'])) {
            $this->xrplTxService->syncTransactions($xrpl_order_meta['destination_account']);

            $tx = $this->xrplTxService->findTransaction(
                $xrpl_order_meta['destination_account'],
                (int) $xrpl_order_meta['destination_tag']
            );

            if ($tx) {
                $txMeta = json_decode($tx['meta'], true);

                if (is_array($txMeta['delivered_amount'])) {
                    $amount = $txMeta['delivered_amount']['value'];
                } else {
                    $amount = dropsToXrp($txMeta['delivered_amount']);
                }

                $tx_order_meta = [
                    'hash' => $tx['hash'],
                    'ctid' => $tx['ctid'],
                    'delivered_amount' => $amount
                ];

                $new_order_meta = array_replace_recursive($xrpl_order_meta, $tx_order_meta);
                $order->update_meta_data( 'xrpl', $new_order_meta );
                $order->save();

                return $tx;
            }
        }

        return null;
    }

    /*
    private function prepareXrpPayment(WC_Order $order): array {

    }

    private function getCurrentXrpPriceForOrder(WC_Order $order): array {
        //TODO: Replace hardcoded price
    }

    private function placeholder(): void {
            'orderId' => $orderId,
            'orderNumber' => $order->getOrderNumber(),
            'currencyCode' => str_replace('XRP/','', $customFields['xrpl']['pairing']),
            'currencySymbol' => $order->getCurrency()->getSymbol(),
            'price' => $orderTransaction->getAmount()->getTotalPrice(),
            'network' => $customFields['xrpl']['network'],
            'destinationAccount' => $customFields['xrpl']['destinatoCartion_account'],
            'destinationTag' => $customFields['xrpl']['destination_tag'],
            'xrpAmount' => $customFields['xrpl']['amount_requested'],
            'exchangeRate' => $customFields['xrpl']['exchange_rate'],
            'showNoTransactionFoundError' => true,

        $xrpldata = [
            'orderId' => $order->get_id(),
            'orderNumber' => '12345',
            'currencyCode' => 'XRP/EUR',
            'currencySymbol' => '€',
            'price' => 17,
            'network' => 'TESTNET',
            'destinationAccount' => 'r932NQQnBaMdUrtEjq1MvAu7T4cji47WQq',
            'destinationTag' => 10001,
            'xrpAmount' => 34,
            'exchangeRate' => 0.5,
            'showNoTransactionFoundError' => true,
        ];
        $order->update_meta_data( 'xrpl', $xrpldata );
    }
    */
}