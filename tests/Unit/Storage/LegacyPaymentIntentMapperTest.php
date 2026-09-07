<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Storage;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Storage\LegacyPaymentIntentMapper;
use PHPUnit\Framework\TestCase;

/**
 * Records as written by plugin versions 0.7 - 0.11 (`version => 1.0`, no
 * `schema_version`) must still be readable as core PaymentIntents.
 */
class LegacyPaymentIntentMapperTest extends TestCase
{
    private LegacyPaymentIntentMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new LegacyPaymentIntentMapper();
    }

    public function testDetectsLegacyRecordsByMissingSchemaVersion(): void
    {
        $this->assertTrue($this->mapper->isLegacy(['version' => 1.0, 'type' => 'xrp']));
        $this->assertFalse($this->mapper->isLegacy(['schema_version' => 1, 'type' => 'xrp-payment']));
    }

    /**
     * A 0.10.1 XRP record: no base_asset/quote_currency yet, amount as float.
     */
    public function testMapsAnXrpRecordFrom0101(): void
    {
        $legacy = [
            'chain' => 'XRPL',
            'network' => 'testnet',
            'version' => 1.0,
            'destination_account' => 'rL7DjHoSvkn8TXYPcv6sBsJRwqdzAc6VxK',
            'destination_tag' => 1536965774,
            'expiry' => 1800000000,
            'pairing' => 'XRP/EUR',
            'exchange_rate' => 0.5,
            'amount_requested' => 200.0,
            'type' => 'xrp',
        ];

        $intent = PaymentIntent::fromArray($this->mapper->toSchemaV1($legacy));

        $this->assertSame(1, $intent->schemaVersion);
        $this->assertSame('xrp-payment', $intent->type);
        $this->assertSame('XRP', $intent->baseAsset);
        $this->assertSame('EUR', $intent->quoteCurrency);
        $this->assertSame('XRP/EUR', $intent->pairing);
        $this->assertSame(0.5, $intent->exchangeRate);
        $this->assertSame(200.0, $intent->amountRequested);
        $this->assertSame(1536965774, $intent->destinationTag);
        $this->assertSame(1800000000, $intent->expiry);
        $this->assertNull($intent->amountPaid);
        $this->assertNull($intent->hash);
    }

    /**
     * A 0.11 stablecoin record that was already paid: `currency` label,
     * `delivered_amount`, base_asset/quote_currency present.
     */
    public function testMapsAFulfilledRlusdRecordFrom011(): void
    {
        $legacy = [
            'chain' => 'XRPL',
            'network' => 'mainnet',
            'version' => 1.0,
            'destination_account' => 'rL7DjHoSvkn8TXYPcv6sBsJRwqdzAc6VxK',
            'destination_tag' => 12345,
            'expiry' => 1800000000,
            'base_asset' => 'RLUSD',
            'quote_currency' => 'USD',
            'pairing' => 'RLUSD/USD',
            'exchange_rate' => 1.0,
            'amount_requested' => ['currency' => '524C555344000000000000000000000000000000', 'value' => '10', 'issuer' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De'],
            'type' => 'rlusd',
            'currency' => 'RLUSD',
            'hash' => 'ABC123',
            'ctid' => 'C000000100000000',
            'delivered_amount' => ['currency' => '524C555344000000000000000000000000000000', 'value' => '10', 'issuer' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De'],
        ];

        $mapped = $this->mapper->toSchemaV1($legacy);
        $intent = PaymentIntent::fromArray($mapped);

        $this->assertArrayNotHasKey('version', $mapped);
        $this->assertArrayNotHasKey('currency', $mapped);
        $this->assertSame('rlusd-payment', $intent->type);
        $this->assertSame('RLUSD', $intent->baseAsset);
        $this->assertSame('10', $intent->amountRequested['value']);
        $this->assertSame('ABC123', $intent->hash);
        $this->assertSame('C000000100000000', $intent->ctid);
        $this->assertSame('10', $intent->amountPaid['value']);
        $this->assertSame('10', $intent->amountPaidValue());
    }

    public function testMapsUsdcAndCoercesTypes(): void
    {
        $legacy = [
            'chain' => 'XRPL',
            'network' => 'testnet',
            'version' => 1.0,
            'destination_account' => 'rAccount',
            'destination_tag' => '4294967295',
            'pairing' => 'USDC/EUR',
            'exchange_rate' => '0.9',
            'amount_requested' => ['currency' => '5553444300000000000000000000000000000000', 'value' => '111.11', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt'],
            'type' => 'usdc',
        ];

        $intent = PaymentIntent::fromArray($this->mapper->toSchemaV1($legacy));

        $this->assertSame('usdc-payment', $intent->type);
        $this->assertSame('USDC', $intent->baseAsset);
        $this->assertSame('EUR', $intent->quoteCurrency);
        $this->assertSame(0.9, $intent->exchangeRate);
        $this->assertSame(4294967295, $intent->destinationTag);
        $this->assertNull($intent->expiry);
    }

    /**
     * An order whose quote never completed (tag reserved, no price) has no
     * amount; that is not a readable intent and must say so, not fake one.
     */
    public function testAnIncompleteRecordStillFailsValidation(): void
    {
        $legacy = [
            'chain' => 'XRPL',
            'network' => 'testnet',
            'version' => 1.0,
            'destination_account' => 'rAccount',
            'destination_tag' => 12345,
        ];

        $this->expectException(\InvalidArgumentException::class);

        PaymentIntent::fromArray($this->mapper->toSchemaV1($legacy));
    }
}
