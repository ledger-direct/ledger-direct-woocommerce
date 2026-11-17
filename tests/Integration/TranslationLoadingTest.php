<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * WordPress loads the catalogues bundled in languages/: just-in-time loading alone only looks
 * in wp-content/languages/, so without load_plugin_textdomain() every shop without a language
 * pack would show English.
 */
class TranslationLoadingTest extends TestCase
{
    private string $locale = 'en_US';

    public function set_up(): void
    {
        parent::set_up();
        if (plugin_basename(LEDGER_DIRECT_PLUGIN_FILE_PATH . 'ledger-direct.php') !== 'ledger-direct/ledger-direct.php') {
            self::markTestSkipped('The plugin is not in wp-content/plugins/ledger-direct of the test install; link it there as CI does.');
        }
        add_filter('determine_locale', [$this, 'locale']);
    }

    public function tear_down(): void
    {
        remove_filter('determine_locale', [$this, 'locale']);
        unload_textdomain('ledger-direct', true);
        parent::tear_down();
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function translations(): array
    {
        return [
            'German' => ['de_DE', 'Zahlung eingegangen'],
            'French' => ['fr_FR', 'Paiement reçu'],
            'Dutch' => ['nl_NL', 'Betaling ontvangen'],
            'Spanish' => ['es_ES', 'Pago recibido'],
            'Portuguese (Portugal)' => ['pt_PT', 'Pagamento recebido'],
        ];
    }

    #[DataProvider('translations')]
    public function testTheBundledCatalogueIsLoaded(string $locale, string $expected): void
    {
        $this->locale = $locale;
        unload_textdomain('ledger-direct', true);

        self::assertSame($expected, __('Payment received', 'ledger-direct'));
    }

    public function testRegisteredAtInit(): void
    {
        self::assertSame(0, has_action('init', [\LedgerDirect::instance(), 'load_translations']));
    }
}
