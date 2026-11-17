<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The bundled catalogues against the code: every text the plugin can show exists in every
 * language, with the same placeholders and markup, and the files WordPress actually reads
 * (.mo for PHP, .json for the block checkout script) say what the .po says.
 *
 * Reads only files of the repository. The extraction follows `wp i18n make-pot` closely
 * enough for this plugin (literal strings in the gettext functions below, with the text
 * domain ledger-direct); the POT must hold exactly those strings, so a text added in the
 * code without regenerating the catalogues fails here.
 *
 * Regenerate: npm run i18n (make-pot, make-mo, make-json --no-purge), then translate the
 * new entries in every .po and run make-mo and make-json again.
 */
final class TranslationCataloguesTest extends TestCase
{
    private const DOMAIN = 'ledger-direct';

    private const LOCALES = ['de_DE', 'fr_FR', 'nl_NL', 'es_ES', 'pt_PT'];

    /** Where the code with translatable texts lives (vendor, tests, dev tools and the copied payment-ui package are not scanned). */
    private const PHP_SOURCES = ['ledger-direct.php', 'includes', 'src'];

    /** The built scripts WordPress loads; resources/ holds their sources. */
    private const JS_SOURCES = ['includes/assets/js'];

    /** The plugin header fields make-pot puts into the POT. */
    private const HEADERS = ['Plugin Name', 'Plugin URI', 'Description', 'Author', 'Author URI'];

    /** Function => position of [singular, plural|null, domain] among the arguments. */
    private const GETTEXT = [
        '__' => [0, null, 1], '_e' => [0, null, 1],
        'esc_html__' => [0, null, 1], 'esc_html_e' => [0, null, 1],
        'esc_attr__' => [0, null, 1], 'esc_attr_e' => [0, null, 1],
        '_x' => [0, null, 2], '_ex' => [0, null, 2],
        'esc_html_x' => [0, null, 2], 'esc_attr_x' => [0, null, 2],
        '_n' => [0, 1, 3], '_n_noop' => [0, 1, 2],
        '_nx' => [0, 1, 4], '_nx_noop' => [0, 1, 3],
    ];

    /**
     * Texts that are the same in every language: names (never translated, see the glossary),
     * the plugin's URLs and author, and texts that are nothing but names and placeholders.
     */
    private const SAME_IN_EVERY_LANGUAGE = [
        'Ledger Direct', 'LedgerDirect', 'Xahau', 'XRP', 'XAH (Xahau)',
        'XRPL Mainnet', 'XRPL Testnet', 'Xahau Mainnet', 'Xahau Testnet',
        '1 %1$s = %2$s %3$s',
        'https://github.com/ledger-direct/ledger-direct-woocommerce',
        'https://www.ledger-direct.com',
        'Alexander Busse | Hardcastle Technologies',
        // The glossary keeps these terms English in French, Dutch, Spanish and Portuguese.
        'Destination tag', 'Transaction', '(token)',
    ];

    public function testThePotHoldsExactlyTheTextsOfTheCode(): void
    {
        $fromCode = array_merge(array_keys(self::phpTexts()), array_keys(self::jsTexts()), self::headerTexts());
        $fromCode = array_values(array_unique($fromCode));
        $inPot = array_keys(self::readPo(self::path('languages/ledger-direct.pot'))['entries']);

        sort($fromCode);
        sort($inPot);

        self::assertSame([], array_values(array_diff($fromCode, $inPot)), 'In the code but not in languages/ledger-direct.pot: run npm run i18n:pot.');
        self::assertSame([], array_values(array_diff($inPot, $fromCode)), 'In languages/ledger-direct.pot but no longer in the code: run npm run i18n:pot.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function locales(): array
    {
        $cases = [];
        foreach (self::LOCALES as $locale) {
            $cases[$locale] = [$locale];
        }

        return $cases;
    }

    #[DataProvider('locales')]
    public function testThePoTranslatesEveryTextOfThePot(string $locale): void
    {
        $pot = self::readPo(self::path('languages/ledger-direct.pot'))['entries'];
        $po = self::readPo(self::poPath($locale));
        $nplurals = $po['nplurals'];

        self::assertSame(2, $nplurals, "$locale: Plural-Forms header");
        self::assertSame([], array_values(array_diff(array_keys($pot), array_keys($po['entries']))), "$locale: texts of the POT missing in the .po");
        self::assertSame([], array_values(array_diff(array_keys($po['entries']), array_keys($pot))), "$locale: obsolete texts in the .po");

        foreach ($po['entries'] as $msgid => $entry) {
            self::assertSame($pot[$msgid]['plural'], $entry['plural'], "$locale: plural of \"$msgid\" differs from the POT");
            self::assertCount($entry['plural'] === null ? 1 : $nplurals, $entry['msgstr'], "$locale: number of translations of \"$msgid\"");

            foreach ($entry['msgstr'] as $index => $msgstr) {
                self::assertNotSame('', trim($msgstr), "$locale: \"$msgid\" [$index] is not translated");

                $source = $index === 0 ? $msgid : (string) $entry['plural'];
                self::assertSame(self::placeholders($source), self::placeholders($msgstr), "$locale: placeholders of \"$msgid\" [$index]");
                self::assertSame(self::tags($source), self::tags($msgstr), "$locale: markup of \"$msgid\" [$index]");

                if (!in_array($msgid, self::SAME_IN_EVERY_LANGUAGE, true)) {
                    self::assertNotSame($source, $msgstr, "$locale: \"$msgid\" [$index] is left in English");
                }
            }
        }
    }

    #[DataProvider('locales')]
    public function testTheMoSaysWhatThePoSays(string $locale): void
    {
        $expected = [];
        foreach (self::readPo(self::poPath($locale))['entries'] as $msgid => $entry) {
            $key = $entry['plural'] === null ? $msgid : $msgid . "\0" . $entry['plural'];
            $expected[$key] = implode("\0", $entry['msgstr']);
        }

        $actual = self::readMo(self::path("languages/ledger-direct-$locale.mo"));
        unset($actual['']);

        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual, "$locale: the .mo is out of date - run npm run i18n:mo");
    }

    #[DataProvider('locales')]
    public function testEveryScriptWithTextsHasItsCatalogue(string $locale): void
    {
        $po = self::readPo(self::poPath($locale))['entries'];
        $scripts = self::jsTextsByFile();
        self::assertNotSame([], $scripts, 'No script with texts found: has the block checkout moved?');

        foreach ($scripts as $file => $texts) {
            // load_script_textdomain() looks the file up by the md5 of the script's path relative to the plugin.
            $json = self::path(sprintf('languages/%s-%s-%s.json', self::DOMAIN, $locale, md5($file)));
            self::assertFileExists($json, "$locale: no catalogue for $file - run npm run i18n:json");

            $data = json_decode((string) file_get_contents($json), true);
            self::assertIsArray($data, "$json is not JSON");
            self::assertSame($file, $data['source'] ?? null, "$json names another script");
            $messages = $data['locale_data']['messages'] ?? [];

            foreach (array_keys($texts) as $msgid) {
                self::assertArrayHasKey($msgid, $messages, "$locale: \"$msgid\" of $file is missing in " . basename($json));
                self::assertSame($po[$msgid]['msgstr'], $messages[$msgid], "$locale: \"$msgid\" in " . basename($json) . ' differs from the .po - run npm run i18n:json');
            }
        }
    }

    /**
     * WordPress reads a script's catalogue only when the handle is registered with
     * wp_set_script_translations() and the plugin's languages/ folder.
     */
    public function testEveryScriptWithTextsLoadsItsTranslations(): void
    {
        $registrations = [];
        foreach (self::phpFiles() as $file) {
            $code = (string) file_get_contents(self::path($file));
            if (preg_match_all("/wp_set_script_translations\\(\\s*'([^']+)'\\s*,\\s*'" . self::DOMAIN . "'\\s*,\\s*LEDGER_DIRECT_PLUGIN_FILE_PATH \\. 'languages\\/'/", $code, $matches) > 0) {
                $registrations[$file] = $code;
            }
        }

        foreach (array_keys(self::jsTextsByFile()) as $script) {
            // The PHP that registers the script names its path below includes/.
            $relative = (string) preg_replace('#^includes/#', '', $script);
            $found = array_filter($registrations, static fn (string $code): bool => str_contains($code, $relative));
            self::assertNotSame([], $found, "$script has texts but no wp_set_script_translations() with the languages/ folder next to its registration");
        }
    }

    // ---------------------------------------------------------------- extraction

    /**
     * @return array<string, true> msgid => true
     */
    private static function phpTexts(): array
    {
        $texts = [];
        foreach (self::phpFiles() as $file) {
            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents(self::path($file))),
                static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ));

            foreach ($tokens as $i => $token) {
                if (!is_array($token) || $token[0] !== T_STRING || !isset(self::GETTEXT[$token[1]]) || ($tokens[$i + 1] ?? null) !== '(') {
                    continue;
                }
                [$singular, $plural, $domain] = self::GETTEXT[$token[1]];
                $arguments = self::literalArguments($tokens, $i + 2);
                if (($arguments[$domain] ?? null) !== self::DOMAIN || !is_string($arguments[$singular] ?? null)) {
                    continue;
                }
                $texts[$arguments[$singular]] = true;
                if ($plural !== null) {
                    self::assertIsString($arguments[$plural] ?? null, "$file: plural of \"{$arguments[$singular]}\" is not a literal");
                }
            }
        }

        return $texts;
    }

    /**
     * The arguments of a call as literal strings, null for anything else.
     *
     * @param list<mixed> $tokens
     * @return list<string|null>
     */
    private static function literalArguments(array $tokens, int $start): array
    {
        $arguments = [];
        $current = null;
        $simple = true;
        $depth = 0;

        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($depth === 0 && ($text === ',' || $text === ')')) {
                $arguments[] = $simple ? $current : null;
                if ($text === ')') {
                    break;
                }
                $current = null;
                $simple = true;
                continue;
            }
            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            }

            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && $current === null && $simple) {
                $current = self::phpString($token[1]);
            } else {
                $simple = false;
            }
        }

        return $arguments;
    }

    private static function phpString(string $literal): string
    {
        $body = substr($literal, 1, -1);

        if ($literal[0] === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $body);
        }

        return stripcslashes($body);
    }

    /**
     * @return array<string, true>
     */
    private static function jsTexts(): array
    {
        $texts = [];
        foreach (self::jsTextsByFile() as $fileTexts) {
            $texts += $fileTexts;
        }

        return $texts;
    }

    /**
     * Texts per built script, keyed by the path relative to the plugin. Only __() is used in the
     * scripts; a script that calls another translation function fails here until this learns it.
     *
     * @return array<string, array<string, true>>
     */
    private static function jsTextsByFile(): array
    {
        $byFile = [];
        foreach (self::files(self::JS_SOURCES, 'js') as $file) {
            $code = (string) file_get_contents(self::path($file));
            $domain = preg_quote(self::DOMAIN, '/');

            self::assertSame(0, preg_match('/\b(_x|_n|_nx)\)?\([^)]*["\']' . $domain . '["\']/', $code), "$file uses a translation function this test does not read");

            if (preg_match_all('/\b__\)?\(\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')\s*,\s*["\']' . $domain . '["\']\s*\)/', $code, $matches) === 0) {
                continue;
            }
            foreach ($matches[1] as $literal) {
                $body = substr($literal, 1, -1);
                if ($literal[0] === "'") {
                    $body = str_replace(["\\'", '"'], ["'", '\\"'], $body);
                }
                $byFile[$file][(string) json_decode('"' . $body . '"')] = true;
            }
        }

        return $byFile;
    }

    /**
     * @return list<string>
     */
    private static function headerTexts(): array
    {
        $header = (string) file_get_contents(self::path('ledger-direct.php'), false, null, 0, 8192);
        $texts = [];
        foreach (self::HEADERS as $field) {
            if (preg_match('/^\s*\*\s*' . preg_quote($field, '/') . ':\s*(.+)$/m', $header, $match) === 1) {
                $texts[] = trim($match[1]);
            }
        }

        return $texts;
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(): array
    {
        return self::files(self::PHP_SOURCES, 'php');
    }

    /**
     * @param list<string> $roots
     * @return list<string> paths relative to the plugin
     */
    private static function files(array $roots, string $extension): array
    {
        $files = [];
        foreach ($roots as $root) {
            $absolute = self::path($root);
            if (is_file($absolute)) {
                $files[] = $root;
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $info) {
                if ($info instanceof \SplFileInfo && $info->getExtension() === $extension) {
                    $files[] = $root . substr($info->getPathname(), strlen($absolute));
                }
            }
        }
        sort($files);

        return $files;
    }

    // ---------------------------------------------------------------- catalogues

    /**
     * A .po or .pot file: entries keyed by msgid (no message contexts in this plugin), and the
     * number of plural forms from the header.
     *
     * @return array{nplurals: int|null, entries: array<string, array{plural: string|null, msgstr: list<string>}>}
     */
    private static function readPo(string $file): array
    {
        self::assertFileExists($file);
        $entries = [];
        $header = '';
        $entry = null;
        $field = null;

        $flush = static function () use (&$entry, &$entries, &$header): void {
            if ($entry === null) {
                return;
            }
            self::assertNull($entry['context'], 'Message contexts are not supported by this test: ' . $entry['msgid']);
            if ($entry['msgid'] === '') {
                $header = $entry['msgstr'][0] ?? '';
            } else {
                ksort($entry['msgstr']);
                $entries[$entry['msgid']] = ['plural' => $entry['plural'], 'msgstr' => array_values($entry['msgstr'])];
            }
            $entry = null;
        };

        foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                if ($line === '' || str_starts_with($line, '#~')) {
                    $flush();
                }
                continue;
            }
            if (preg_match('/^(msgctxt|msgid|msgid_plural|msgstr(?:\[(\d+)\])?) (".*")$/', $line, $m) === 1) {
                if ($m[1] === 'msgctxt' || ($m[1] === 'msgid' && $entry !== null && $field !== 'msgctxt')) {
                    $flush();
                }
                $entry ??= ['context' => null, 'msgid' => '', 'plural' => null, 'msgstr' => []];
                $value = self::poString($m[3]);
                $field = $m[1];
                if ($m[1] === 'msgctxt') {
                    $entry['context'] = $value;
                } elseif ($m[1] === 'msgid') {
                    $entry['msgid'] = $value;
                } elseif ($m[1] === 'msgid_plural') {
                    $entry['plural'] = $value;
                } else {
                    $index = ($m[2] ?? '') === '' ? 0 : (int) $m[2];
                    $entry['msgstr'][$index] = $value;
                    $field = 'msgstr:' . $index;
                }
                continue;
            }
            if (preg_match('/^(".*")$/', $line, $m) === 1 && $entry !== null) {
                $value = self::poString($m[1]);
                if ($field === 'msgid') {
                    $entry['msgid'] .= $value;
                } elseif ($field === 'msgid_plural') {
                    $entry['plural'] .= $value;
                } elseif ($field === 'msgctxt') {
                    $entry['context'] .= $value;
                } elseif (is_string($field) && str_starts_with($field, 'msgstr:')) {
                    $entry['msgstr'][(int) substr($field, 7)] .= $value;
                }
            }
        }
        $flush();

        $nplurals = preg_match('/Plural-Forms:\s*nplurals\s*=\s*(\d+)/', $header, $m) === 1 ? (int) $m[1] : null;

        return ['nplurals' => $nplurals, 'entries' => $entries];
    }

    private static function poString(string $quoted): string
    {
        return stripcslashes(substr($quoted, 1, -1));
    }

    /**
     * A .mo file as WordPress reads it: original => translation, plurals joined by NUL.
     *
     * @return array<string, string>
     */
    private static function readMo(string $file): array
    {
        self::assertFileExists($file);
        $data = (string) file_get_contents($file);
        $magic = unpack('V', substr($data, 0, 4))[1];
        $format = $magic === 0x950412de ? 'V' : 'N';
        [, $revision, $count, $originals, $translations] = array_values(unpack($format . '5', substr($data, 0, 20)));
        self::assertSame(0, $revision, "$file: unknown .mo revision");

        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            [$originalLength, $originalOffset] = array_values(unpack($format . '2', substr($data, $originals + $i * 8, 8)));
            [$translationLength, $translationOffset] = array_values(unpack($format . '2', substr($data, $translations + $i * 8, 8)));
            $messages[substr($data, $originalOffset, $originalLength)] = substr($data, $translationOffset, $translationLength);
        }

        return $messages;
    }

    // ---------------------------------------------------------------- comparison

    /**
     * Unnumbered placeholders in their order, numbered ones as a sorted list (a translation may
     * reorder those), and the script's %name% placeholders.
     *
     * @return array{ordered: list<string>, numbered: list<string>, named: list<string>}
     */
    private static function placeholders(string $text): array
    {
        preg_match_all('/%(?:(\d+\$)?[-+ 0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX%])/', $text, $printf, PREG_SET_ORDER);
        $ordered = [];
        $numbered = [];
        foreach ($printf as $match) {
            if (($match[1] ?? '') !== '') {
                $numbered[] = $match[0];
            } elseif ($match[0] !== '%%') {
                $ordered[] = $match[0];
            }
        }
        sort($numbered);
        preg_match_all('/%[a-z_]+%/', $text, $named);
        $names = $named[0];
        sort($names);

        return ['ordered' => $ordered, 'numbered' => $numbered, 'named' => $names];
    }

    /**
     * @return list<string>
     */
    private static function tags(string $text): array
    {
        preg_match_all('/<\/?[a-zA-Z][^>]*>/', $text, $matches);
        $tags = $matches[0];
        sort($tags);

        return $tags;
    }

    private static function poPath(string $locale): string
    {
        return self::path(sprintf('languages/%s-%s.po', self::DOMAIN, $locale));
    }

    private static function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }
}
