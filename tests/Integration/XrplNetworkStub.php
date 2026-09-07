<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

/**
 * Answers the core's oracle and JSON-RPC calls offline, through the
 * `pre_http_request` filter every WordPress HTTP request passes.
 *
 * Deliberately offline: the tests here are about the WooCommerce adapter,
 * not about whether Binance is up.
 */
final class XrplNetworkStub
{
    public const ACCOUNT = 'rL7DjHoSvkn8TXYPcv6sBsJRwqdzAc6VxK';

    /** @var callable|null */
    private $filter = null;

    /** @var array<int, array<string, mixed>> account_tx transactions to return */
    private array $transactions = [];

    private float $xrpRate;

    private float $stablecoinRate;

    /** @var string[] URLs requested, for assertions. */
    public array $requests = [];

    public function __construct(float $xrpRate = 0.5, float $stablecoinRate = 0.9)
    {
        $this->xrpRate = $xrpRate;
        $this->stablecoinRate = $stablecoinRate;
    }

    public function install(): void
    {
        $this->filter = fn ($preempt, array $args, string $url) => $this->answer($url, $args);
        add_filter('pre_http_request', $this->filter, 10, 3);
    }

    public function remove(): void
    {
        if ($this->filter !== null) {
            remove_filter('pre_http_request', $this->filter, 10);
            $this->filter = null;
        }
    }

    /**
     * Queues an incoming XRP payment for the next account_tx call.
     */
    public function addXrpPayment(int $destinationTag, string $drops, string $hash, int $ledgerIndex = 90000000): void
    {
        $this->transactions[] = [
            'tx' => [
                'TransactionType' => 'Payment',
                'Account' => 'rSenderAccountXXXXXXXXXXXXXXXXXXXX',
                'Destination' => self::ACCOUNT,
                'DestinationTag' => $destinationTag,
                'Amount' => $drops,
                'hash' => $hash,
                'ctid' => sprintf('C%07X%04X%04X', $ledgerIndex, 1, 1),
                'ledger_index' => $ledgerIndex,
                'date' => 780000000,
            ],
            'meta' => [
                'TransactionIndex' => 1,
                'TransactionResult' => 'tesSUCCESS',
                'delivered_amount' => $drops,
            ],
            'validated' => true,
        ];
    }

    /**
     * Queues an incoming issued-currency payment for the next account_tx call.
     *
     * @param array{currency: string, value: string, issuer: string} $amount
     */
    public function addIssuedCurrencyPayment(int $destinationTag, array $amount, string $hash, int $ledgerIndex = 90000000): void
    {
        $this->addXrpPayment($destinationTag, '0', $hash, $ledgerIndex);
        $last = array_key_last($this->transactions);
        $this->transactions[$last]['tx']['Amount'] = $amount;
        $this->transactions[$last]['meta']['delivered_amount'] = $amount;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function answer(string $url, array $args): array
    {
        $this->requests[] = $url;

        if (str_contains($url, 'api.binance.com')) {
            return self::json(['price' => (string) $this->xrpRate]);
        }

        if (str_contains($url, 'api.coingecko.com')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $rate = $query['ids'] === 'ripple' ? $this->xrpRate : $this->stablecoinRate;

            return self::json([$query['ids'] => [$query['vs_currencies'] => $rate]]);
        }

        if (str_contains($url, 'api.kraken.com')) {
            return self::json(['error' => [], 'result' => ['XXRPZEUR' => ['c' => [(string) $this->xrpRate, '1']]]]);
        }

        // XRPL JSON-RPC
        $request = json_decode((string) ($args['body'] ?? ''), true);
        $method = $request['method'] ?? '';

        if ($method === 'account_tx') {
            $params = $request['params'][0] ?? [];
            $min = (int) ($params['ledger_index_min'] ?? 0);
            $transactions = array_values(array_filter(
                $this->transactions,
                static fn (array $t): bool => (int) $t['tx']['ledger_index'] >= $min
            ));

            return self::json(['result' => ['status' => 'success', 'account' => self::ACCOUNT, 'transactions' => $transactions]]);
        }

        return self::json(['result' => ['status' => 'error', 'error' => 'unknownCmd']], 400);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function json(array $data, int $status = 200): array
    {
        return [
            'headers' => ['content-type' => 'application/json'],
            'body' => (string) wp_json_encode($data),
            'response' => ['code' => $status, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }
}
