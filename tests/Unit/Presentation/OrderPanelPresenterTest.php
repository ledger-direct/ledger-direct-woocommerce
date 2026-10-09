<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Presentation;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Presentation\OrderPanelPresenter;
use PHPUnit\Framework\TestCase;

/**
 * The admin panel as scalars: the five words, the amounts, the transactions.
 */
final class OrderPanelPresenterTest extends TestCase
{
    private const ACCOUNT = 'rL7DjHoSvkn8TXYPcv6sBsJRwqdzAc6VxK';

    private function xrpIntent(): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'XRP', quoteCurrency: 'EUR',
            pairing: 'XRP/EUR', exchangeRate: 0.5, amountRequested: 200.0,
            destinationAccount: self::ACCOUNT, destinationTag: 123456, expiry: time() + 600,
        );
    }

    private function transaction(string $hash, array $meta): XrplTransaction
    {
        return new XrplTransaction(
            network: 'testnet', ledgerIndex: '90000000', hash: $hash, ctid: 'C0000001', account: 'rSender',
            destination: self::ACCOUNT, destinationTag: 123456, date: 800000000, meta: $meta, tx: ['TransactionType' => 'Payment'],
        );
    }

    public function testAWaitingOrderShowsTheRequestAndNoPayment(): void
    {
        $panel = OrderPanelPresenter::present($this->xrpIntent(), new SettlementPolicy());

        self::assertSame('waiting', $panel['state']);
        self::assertSame('Waiting for payment', $panel['state_label']);
        self::assertSame('200', $panel['amount_requested']);
        self::assertNull($panel['amount_paid']);
        // Nothing arrived, so the whole request is still due
        self::assertSame('200', $panel['shortfall']);
        self::assertSame('0.5', $panel['rate']);
        self::assertSame('XRP/EUR', $panel['pairing']);
        self::assertSame('123456', $panel['destination_tag']);
        self::assertNull($panel['issuer']);
        self::assertFalse($panel['is_token']);
        self::assertSame('https://testnet.xrpl.org/transactions/', $panel['explorer']);
        self::assertSame([], $panel['transactions']);
    }

    public function testAShortPaymentShowsWhatArrivedAndTheShortfallWithTheTransactions(): void
    {
        $intent = $this->xrpIntent()->withFulfillment('HASH-HALF', 150.0, 'C0000001');
        $transactions = [
            $this->transaction('HASH-HALF', ['delivered_amount' => '150000000']),
            $this->transaction('HASH-ESCROW', []),
        ];

        $panel = OrderPanelPresenter::present($intent, new SettlementPolicy(), $transactions);

        self::assertSame('partial', $panel['state']);
        self::assertSame('Partially paid', $panel['state_label']);
        self::assertSame('150', $panel['amount_paid']);
        self::assertSame('50', $panel['shortfall']);
        self::assertSame('HASH-HALF', $panel['hash']);
        self::assertSame('150', $panel['transactions'][0]['delivered']);
        self::assertFalse($panel['transactions'][0]['is_issued']);
        self::assertSame('2025-05-08 06:13:20', $panel['transactions'][0]['date']);
        self::assertSame('https://testnet.xrpl.org/transactions/HASH-HALF', $panel['transactions'][0]['explorer_url']);
        self::assertNull($panel['transactions'][1]['delivered']);
    }

    public function testAWrongTokenIsNamedAndTheIssuerShown(): void
    {
        $intent = PaymentIntent::quote(
            type: 'rlusd-payment', chain: 'XRPL', network: 'mainnet', baseAsset: 'RLUSD', quoteCurrency: 'EUR',
            pairing: 'RLUSD/EUR', exchangeRate: 0.9,
            amountRequested: ['currency' => '524C555344000000000000000000000000000000', 'value' => '111.12', 'issuer' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De'],
            destinationAccount: self::ACCOUNT, destinationTag: 7, expiry: time() + 600,
        )->withFulfillment('HASH-WRONG', ['currency' => '524C555344000000000000000000000000000000', 'value' => '111.12', 'issuer' => 'rSomeOtherIssuerXXXXXXXXXXXXXXXXXX']);

        $panel = OrderPanelPresenter::present($intent, new SettlementPolicy());

        self::assertSame('wrong_asset', $panel['state']);
        self::assertSame('Paid in the wrong token, not credited', $panel['state_label']);
        self::assertTrue($panel['is_token']);
        self::assertSame('rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De', $panel['issuer']);
        self::assertSame('111.12', $panel['amount_paid']);
        self::assertSame('111.12', $panel['shortfall']);
        self::assertSame('https://livenet.xrpl.org/transactions/', $panel['explorer']);
    }
}
