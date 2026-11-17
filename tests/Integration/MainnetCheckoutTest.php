<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Port\WpConfigProvider;
use Hardcastle\LedgerDirect\Service\ServiceFactory;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;

/**
 * A shop set to mainnet asks the mainnet node about the mainnet account when it lists the
 * payment methods — the branch the other tests, which all run on the testnet, never enter
 * (Documentation/Mainnet-Testing.md, K1). No request leaves the process: every HTTP call is
 * answered here with "account not found".
 */
class MainnetCheckoutTest extends TestCase
{
    private const MAINNET_ACCOUNT = 'rPEPPER7kfTD9w2To4CQk6UCfuHM9c6GDY';
    private const TESTNET_ACCOUNT = 'rL7DjHoSvkn8TXYPcv6sBsJRwqdzAc6VxK';

    /** @var list<array{url: string, method: string, account: string}> */
    private array $asked = [];

    /** @var callable */
    private $filter;

    protected function setUp(): void
    {
        parent::setUp();

        update_option(WpConfigProvider::OPTION_NAME, [
            'enabled' => 'yes',
            WpConfigProvider::KEY_NETWORK => 'mainnet',
            WpConfigProvider::KEY_MAINNET_ACCOUNT => self::MAINNET_ACCOUNT,
            WpConfigProvider::KEY_TESTNET_ACCOUNT => self::TESTNET_ACCOUNT,
            WpConfigProvider::KEY_RLUSD_ENABLED => 'yes',
        ]);

        $this->filter = function ($preempt, array $args, string $url): array {
            $request = json_decode((string) ($args['body'] ?? ''), true);
            $this->asked[] = [
                'url' => $url,
                'method' => (string) ($request['method'] ?? ''),
                'account' => (string) ($request['params'][0]['account'] ?? ''),
            ];

            return [
                'headers' => ['content-type' => 'application/json'],
                'body' => (string) wp_json_encode(['result' => ['status' => 'error', 'error' => 'actNotFound']]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies' => [],
                'filename' => null,
            ];
        };
        add_filter('pre_http_request', $this->filter, 10, 3);
        ServiceFactory::setInstance(null);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', $this->filter, 10);
        ServiceFactory::setInstance(null);

        parent::tearDown();
    }

    public function testTheCheckoutAsksTheMainnetNodeAboutTheMainnetAccount(): void
    {
        LedgerDirectPaymentGateway::offered_payment_types(ledger_direct_get_configuration());

        $accountInfo = array_values(array_filter($this->asked, static fn (array $call): bool => $call['method'] === 'account_info'));
        self::assertNotEmpty($accountInfo, 'the receiving account is checked');
        foreach ($accountInfo as $call) {
            self::assertSame('https://xrplcluster.com/', $call['url']);
        }
        self::assertSame([self::MAINNET_ACCOUNT], array_values(array_unique(array_column($accountInfo, 'account'))));
    }
}
