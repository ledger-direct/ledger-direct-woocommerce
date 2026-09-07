<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Storage;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Translates the order metadata written by plugin versions up to 0.11
 * (`version => 1.0`, no `schema_version`) into the core's PaymentIntent
 * schema v1, so orders quoted before the core retrofit can still be paid,
 * re-quoted and settled after the update.
 *
 * Read-side only: the next write (a refreshed quote, a fulfillment) stores
 * the record in the new shape, and this mapper is not consulted again.
 */
final class LegacyPaymentIntentMapper
{
    private const TYPE_BY_LEGACY_TYPE = [
        'xrp' => 'xrp-payment',
        'rlusd' => 'rlusd-payment',
        'usdc' => 'usdc-payment',
    ];

    private const BASE_ASSET_BY_LEGACY_TYPE = [
        'xrp' => 'XRP',
        'rlusd' => 'RLUSD',
        'usdc' => 'USDC',
    ];

    /**
     * @param array<string, mixed> $data
     */
    public function isLegacy(array $data): bool
    {
        return !array_key_exists('schema_version', $data);
    }

    /**
     * @param array<string, mixed> $data A legacy record.
     * @return array<string, mixed> Schema v1 data, ready for PaymentIntent::fromArray().
     */
    public function toSchemaV1(array $data): array
    {
        $legacyType = strtolower((string) ($data['type'] ?? ''));
        $baseAsset = (string) ($data['base_asset'] ?? self::BASE_ASSET_BY_LEGACY_TYPE[$legacyType] ?? '');
        $pairing = (string) ($data['pairing'] ?? '');

        $quoteCurrency = $data['quote_currency'] ?? null;
        if ($quoteCurrency === null && str_contains($pairing, '/')) {
            $quoteCurrency = substr($pairing, strpos($pairing, '/') + 1);
        }

        $record = [
            'schema_version' => 1,
            'type' => self::TYPE_BY_LEGACY_TYPE[$legacyType] ?? $legacyType,
            'chain' => (string) ($data['chain'] ?? 'XRPL'),
            'network' => (string) ($data['network'] ?? 'mainnet'),
            'base_asset' => $baseAsset,
            'quote_currency' => (string) $quoteCurrency,
            'pairing' => $pairing !== '' ? $pairing : $baseAsset . '/' . $quoteCurrency,
            'destination_account' => (string) ($data['destination_account'] ?? ''),
        ];

        if (array_key_exists('exchange_rate', $data)) {
            $record['exchange_rate'] = (float) $data['exchange_rate'];
        }

        if (array_key_exists('amount_requested', $data)) {
            $record['amount_requested'] = self::amount($data['amount_requested']);
        }

        if (array_key_exists('destination_tag', $data)) {
            $record['destination_tag'] = (int) $data['destination_tag'];
        }

        if (isset($data['expiry'])) {
            $record['expiry'] = (int) $data['expiry'];
        }

        if (isset($data['hash'])) {
            $record['hash'] = (string) $data['hash'];
        }

        if (isset($data['ctid'])) {
            $record['ctid'] = (string) $data['ctid'];
        }

        $amountPaid = $data['amount_paid'] ?? $data['delivered_amount'] ?? null;
        if ($amountPaid !== null) {
            $record['amount_paid'] = self::amount($amountPaid);
        }

        return $record;
    }

    /**
     * Legacy XRP amounts were stored as whatever the division produced
     * (float, sometimes int); issued-currency amounts as the full object.
     */
    private static function amount(mixed $amount): float|array
    {
        if (is_array($amount)) {
            return $amount;
        }

        return (float) $amount;
    }
}
