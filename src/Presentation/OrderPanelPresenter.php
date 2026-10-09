<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Presentation;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use UnexpectedValueException;

/**
 * The LedgerDirect panel on the admin order page, as scalars.
 *
 * What the merchant could not see before: the payment state in the core's
 * five words, what was asked for, what arrived, what is still missing, and
 * every transaction on the order's destination tag with a link to the
 * explorer. The order status "XRPL payment incomplete" says *that*
 * something is wrong; this says *what*.
 *
 * Read-only on purpose. Nothing here changes the order: settling is the
 * sync's job, and a merchant who wants to accept a short payment changes
 * the order status like for any other payment method.
 *
 * The view under includes/ is not scoped by the release build and must not
 * name a core class, so everything it needs is in this array.
 */
final class OrderPanelPresenter
{
    private const EXPLORER = [
        'mainnet' => 'https://livenet.xrpl.org/transactions/',
        'testnet' => 'https://testnet.xrpl.org/transactions/',
    ];

    /** Seconds between the Unix epoch and the Ripple epoch (2000-01-01). */
    private const RIPPLE_EPOCH_OFFSET = 946684800;

    /**
     * @param XrplTransaction[] $transactions every transaction on the order's destination tag, newest first
     * @return array{
     *   state: string, state_label: string, asset: string, network: string, is_token: bool,
     *   amount_requested: string, amount_paid: string|null, shortfall: string|null,
     *   rate: string, pairing: string, destination_account: string, destination_tag: string,
     *   issuer: string|null, expiry: int|null, hash: string|null, ctid: string|null, explorer: string|null,
     *   transactions: list<array{hash: string, delivered: string|null, is_issued: bool, date: string, explorer_url: string|null}>
     * }
     */
    public static function present(PaymentIntent $intent, SettlementPolicy $policy, array $transactions = []): array
    {
        $status = PaymentStatus::fromIntent($intent, $policy);
        $state = $status->state();
        $explorer = self::EXPLORER[$intent->network] ?? null;

        $rows = [];
        foreach ($transactions as $transaction) {
            $rows[] = self::presentTransaction($transaction, $explorer);
        }

        return [
            'state' => $state,
            'state_label' => self::stateLabel($state),
            'asset' => $intent->baseAsset,
            'network' => $intent->network,
            'is_token' => is_array($intent->amountRequested),
            'amount_requested' => $intent->amountRequestedValue(),
            'amount_paid' => $intent->amountPaidValue(),
            'shortfall' => $policy->shortfall($intent),
            'rate' => PaymentPagePresenter::rate($intent->exchangeRate),
            'pairing' => $intent->pairing,
            'destination_account' => $intent->destinationAccount,
            'destination_tag' => (string) $intent->destinationTag,
            'issuer' => is_array($intent->amountRequested) ? (string) ($intent->amountRequested['issuer'] ?? '') : null,
            'expiry' => $intent->expiry,
            'hash' => $intent->hash,
            'ctid' => $intent->ctid,
            'explorer' => $explorer,
            'transactions' => $rows,
        ];
    }

    /**
     * @return array{hash: string, delivered: string|null, is_issued: bool, date: string, explorer_url: string|null}
     */
    private static function presentTransaction(XrplTransaction $transaction, ?string $explorer): array
    {
        try {
            $delivered = $transaction->getDeliveredAmount();
        } catch (UnexpectedValueException) {
            $delivered = null;
        }

        return [
            'hash' => $transaction->hash,
            'delivered' => self::plainAmount($delivered),
            // Issued currencies carry their code as a 40-hex string on the
            // ledger; the merchant reads the asset from the request above.
            'is_issued' => is_array($delivered),
            'date' => gmdate('Y-m-d H:i:s', self::RIPPLE_EPOCH_OFFSET + $transaction->date),
            'explorer_url' => $explorer === null ? null : $explorer . $transaction->hash,
        ];
    }

    /**
     * An amount as the ledger delivered it, as a plain decimal string.
     *
     * @param float|array{currency: string, value: string, issuer: string}|null $amount
     */
    private static function plainAmount(float|array|null $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        if (is_array($amount)) {
            return (string) ($amount['value'] ?? '');
        }

        $decimal = number_format($amount, 6, '.', '');

        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    private static function stateLabel(string $state): string
    {
        return match ($state) {
            PaymentStatus::SETTLED => __('Paid', 'ledger-direct'),
            PaymentStatus::PARTIAL => __('Partially paid', 'ledger-direct'),
            PaymentStatus::WRONG_ASSET => __('Paid in the wrong token, not credited', 'ledger-direct'),
            PaymentStatus::EXPIRED => __('Quote expired, nothing received', 'ledger-direct'),
            default => __('Waiting for payment', 'ledger-direct'),
        };
    }
}
