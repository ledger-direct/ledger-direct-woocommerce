<?php

namespace Hardcastle\LedgerDirect\Service;

use Exception;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;
use WC_Order;
use function XRPL_PHP\Sugar\dropsToXrp;

class OrderTransactionService
{
    public static self|null $_instance = null;

    private LedgerDirectPaymentGateway $ledgerDirectGateway;

    private XrplTxService $xrplTxService;

    public static function instance(): self
    {
        if (self::$_instance == null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function __construct()
    {
        //$this->ledgerDirectGateway = LedgerDirectPaymentGateway::instance();
        $this->xrplTxService = XrplTxService::instance();
    }

    /**
     * Get XRP price for Order
     *
     * @param WC_Order $order
     * @return array
     * @throws Exception
     */
    public function getCurrentXrpPriceForOrder(WC_Order $order): array {
        //TODO: Implement currently hardcoded stuff
        $orderTotal = $order->get_total();
        return [
            'pairing' => 'XRP/EUR',
            'exchange_rate' => 0.5,
            'amount_requested' => $orderTotal / 0.5
        ];
        /*
        $currency = $this->currencyRepository->search(new Criteria([$order->getCurrencyId()]), $context)->first();
        $currencyAmountTotal = $order->getAmountTotal();
        $xrpUnitPrice = $this->priceProvider->getCurrentExchangeRate($currency->getIsoCode());

        return [
            'pairing' => XrpPriceProvider::CRYPTO_CODE . '/' . $currency->getIsoCode(),
            'exchange_rate' => $xrpUnitPrice,
            'amount_requested' => $currencyAmountTotal / $xrpUnitPrice
        ];
        */
    }

    /**
     * Get initial order metadata for XRPL payments
     *
     * @param WC_Order $order
     * @return array
     * @throws Exception
     */
    public function prepareXrplOrderTransaction(WC_Order $order): array {
        $paymentMethod = 'ledger-direct';

        /*
        $network = $this->configurationService->isTest() ? 'Testnet' : 'Mainnet'; // TODO: Use NetworkId
        $destination = $this->configurationService->getDestinationAccount();
        $destinationTag = $this->xrplSyncService->generateDestinationTag();
        */

        $network = $this->ledgerDirectGateway->get_option('xrpl_network'); // TODO: Use NetworkId
        $destination = ($network === 'mainnet') ? $this->ledgerDirectGateway->get_option('xrpl_mainnet_destination_account') : $this->ledgerDirectGateway->get_option('xrpl_testnet_destination_account');
        $destinationTag = $this->xrplTxService->generateDestinationTag($destination);

        $xrplCustomFields = [
            'type' => 'xrp-payment',
            'network' => $network,
            'destination_account' => $destination,
            'destination_tag' => $destinationTag
        ];

        $xrpPriceCustomFields = [
            $this->getCurrentXrpPriceForOrder($order)
        ];

        return array_replace_recursive($xrplCustomFields, $xrpPriceCustomFields);


        // Additional XRP / Token routine
        /*
        match ($paymentMethod->getId()) {
            PaymentMethodInstaller::XRP_PAYMENT_ID => $this->prepareXrpPayment($order, $orderTransaction, $context),
            PaymentMethodInstaller::TOKEN_PAYMENT_ID => $this->prepareTokenPayment($order, $orderTransaction, $context),
        };
        */
    }

    /**
     *
     *
     * @param WC_Order $order
     * @return bool
     */
    public function checkPayment(WC_Order $order): bool {
        $xrpl_order_meta = $order->get_meta('xrpl');

        return (isset($xrpl_order_meta['hash']) && isset($xrpl_order_meta['ctid']));
    }

    /**
     * @param WC_Order $order
     * @return array|null
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
                    'ctid' => $xrpl_order_meta['ctid'], // TODO: Add CTID here
                    'amount_paid' => $amount
                ];

                $new_order_meta = array_replace_recursive($xrpl_order_meta, $tx_order_meta);
                $order->update_meta_data( 'xrpl', $new_order_meta );

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
            'destinationAccount' => $customFields['xrpl']['destination_account'],
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