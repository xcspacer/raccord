<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * Every text a user can see goes through the translation files: a word written literally in a view or in a script
 * would stay in one language whatever the user picked. The scan is a heuristic (it reads the source, not the
 * rendered page), so it only looks where screen texts live: Blade text nodes and text attributes, and the string
 * literals of the scripts that carry natural-language words.
 */
class HardCodedTextTest extends TestCase
{
    /**
     * Attributes whose value is read by people (or by assistive technology).
     */
    private const string TEXT_ATTRIBUTES = 'title|placeholder|aria-label|aria-description|aria-placeholder|aria-roledescription|aria-valuetext|alt|label|wire:confirm|wire:confirm\.prompt|data-confirm|data-title|data-label|data-message|data-text|data-empty|data-placeholder|data-tooltip';

    /**
     * Scripts allowed to carry literal natural-language texts, and why.
     *
     * @var array<string, string>
     */
    private const array SCRIPT_ALLOWLIST = [
        // Contents of the OTDR deliverables (DCO, .txt, measurement dossier, failure report): the operator's contractual
        // format, always in French whatever the screen language (ROADMAP, phase 10 decision).
        'resources/js/otdr/export/format.ts' => 'contractual OTDR deliverable texts',
    ];

    /**
     * Views allowed to show some literal texts, and why.
     *
     * @var array<string, list<string>>
     */
    private const array VIEW_ALLOWLIST = [];

    /**
     * Literal fragments that are not language: units, codes and proper nouns written the same in every language.
     *
     * @var list<string>
     */
    private const array NEUTRAL_WORDS = [
        'Waze', 'Google Maps', 'OTDR', 'PDF', 'ZIP', 'GPS', 'SIRET', 'TVA', 'IBAN', 'BIC', 'URL', 'Excel',
    ];

    public function test_views_have_no_hard_coded_text(): void
    {
        $findings = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $allowed = self::VIEW_ALLOWLIST[$this->relative($file)] ?? [];

            foreach (array_diff($this->bladeTexts($file->getContents()), $allowed) as $text) {
                $findings[] = $this->relative($file).': '.$text;
            }
        }

        $this->assertSame([], $findings, 'Hard-coded texts in views (use __()):');
    }

    public function test_scripts_have_no_hard_coded_text(): void
    {
        $findings = [];

        foreach ($this->scripts() as $file) {
            if (array_key_exists($this->relative($file), self::SCRIPT_ALLOWLIST)) {
                continue;
            }

            foreach ($this->scriptTexts($file->getContents()) as $text) {
                $findings[] = $this->relative($file).': '.$text;
            }
        }

        $this->assertSame([], $findings, 'Hard-coded texts in scripts (pass translations from the page):');
    }

    public function test_the_scan_catches_literal_texts(): void
    {
        $this->assertNotEmpty($this->bladeTexts('<button type="button" wire:click="save">Enregistrer</button>'));
        $this->assertNotEmpty($this->bladeTexts('<button type="button" title="Save changes">{{ $icon }}</button>'));
        $this->assertNotEmpty($this->bladeTexts('<input placeholder="Rechercher…">'));
        $this->assertNotEmpty($this->bladeTexts('<button wire:confirm="Supprimer ?">x</button>'));
        $this->assertNotEmpty($this->bladeTexts("<span>{{ \$distance.' km' }}</span>"));
        $this->assertNotEmpty($this->bladeTexts("<span>{{ \$done ? 'Oui' : 'Non' }}</span>"));
        $this->assertNotEmpty($this->scriptTexts("button.textContent = 'Enregistrer';"));
        $this->assertNotEmpty($this->scriptTexts('const label = "Save changes";'));
        $this->assertNotEmpty($this->scriptTexts("window.alert('Erreur réseau');"));

        $this->assertSame([], $this->bladeTexts('<button title="{{ __(\'app.save\') }}">{{ __(\'app.save\') }}</button>'));
        $this->assertSame([], $this->bladeTexts("@if (\$open)\n<span class=\"badge is-on\">✓ {{ \$count }}</span>\n@endif"));
        $this->assertSame([], $this->scriptTexts("import { boot } from './audit/index.js';\nel.classList.toggle('is-visible is-open');\nconsole.warn('Service worker failed');"));
    }

    /**
     * Literal texts left in a Blade template once its PHP parts are removed.
     *
     * @return list<string>
     */
    private function bladeTexts(string $source): array
    {
        $template = $this->stripBlade($source);
        $texts = $this->echoedLiterals($source);

        preg_match_all('/(?<![\w:.-])(?:'.self::TEXT_ATTRIBUTES.')\s*=\s*("[^"]*"|\'[^\']*\')/i', $template, $attributes);

        foreach ($attributes[1] as $value) {
            if ($this->isText(substr($value, 1, -1))) {
                $texts[] = trim($value);
            }
        }

        preg_match_all('/<(?:input|button)\b[^>]*\btype\s*=\s*"(?:submit|button|reset)"[^>]*\bvalue\s*=\s*"([^"]*)"/i', $template, $buttons);

        foreach ($buttons[1] as $value) {
            if ($this->isText($value)) {
                $texts[] = 'value="'.$value.'"';
            }
        }

        $content = preg_replace('/<(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/', "\n", $template) ?? '';

        foreach (preg_split('/\n/', $content) ?: [] as $line) {
            if ($this->isText($line)) {
                $texts[] = trim($line);
            }
        }

        return $texts;
    }

    /**
     * PHP string literals written inside echoes that carry words (« ' km' », « 'Oui' : 'Non' »): translation keys,
     * date formats and markup are left aside.
     *
     * @return list<string>
     */
    private function echoedLiterals(string $source): array
    {
        $source = preg_replace('/\{\{--.*?--\}\}/s', ' ', $source) ?? '';
        $texts = [];

        preg_match_all('/\{\{(.*?)\}\}|\{!!(.*?)!!\}/s', $source, $echoes, PREG_SET_ORDER);

        foreach ($echoes as $echo) {
            $expression = ($echo[2] ?? '') !== '' ? $echo[2] : $echo[1];

            preg_match_all('/(?<before>[\w>]*\(\s*|\[\s*|=>\s*|===?\s*|!==?\s*|\S?)(?<quote>[\'"])(?<value>(?:(?!\k<quote>)[^\\\\]|\\\\.)*)\k<quote>/', $expression, $literals, PREG_SET_ORDER);

            foreach ($literals as $literal) {
                $value = $literal['value'];

                if (preg_match('/(?:__|trans|trans_choice|Format|format|route|asset|url|config|has|is|get|when|where|in_array|str_\w+|explode|implode|ucfirst|lower|upper|class|attributes|json_encode|vite|now|setTimezone|timezone)\(\s*$|\[\s*$|^(?:===?|!==?)/i', $literal['before'])) {
                    continue;
                }

                if (preg_match('/^[\w-]*[.\/][\w.\/-]*$|^[a-z_][\w-]*$|^[\w.:-]+\(|[<>]|^(?:is|has)-|^#?[a-z0-9_-]+(?: [a-z0-9_-]+)*$/', $value)) {
                    continue;
                }

                if ($this->isText($value)) {
                    $texts[] = '{{ '.trim($expression).' }}';
                }
            }
        }

        return $texts;
    }

    /**
     * Removes comments, scripts, styles, echoes, PHP blocks and directives (with their arguments) from a template.
     */
    private function stripBlade(string $source): string
    {
        $source = preg_replace([
            '/\{\{--.*?--\}\}/s',
            '/<!--.*?-->/s',
            '/<script\b.*?<\/script>/is',
            '/<style\b.*?<\/style>/is',
            '/@php\b(?!\s*\().*?@endphp/s',
            '/<\?php.*?\?>/s',
            '/\{!!.*?!!\}/s',
            '/\{\{.*?\}\}/s',
        ], ' ', $source) ?? '';

        $output = '';
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            if ($source[$i] === '@' && preg_match('/\G@[a-zA-Z]+/', $source, $match, 0, $i) && ($i === 0 || ! preg_match('/[\w.@]/', $source[$i - 1]))) {
                $i += strlen($match[0]);

                while ($i < $length && $source[$i] === ' ') {
                    $i++;
                }

                if ($i < $length && $source[$i] === '(') {
                    $i = $this->closingParenthesis($source, $i);
                } else {
                    $i--;
                }

                $output .= ' ';

                continue;
            }

            $output .= $source[$i];
        }

        return $output;
    }

    private function closingParenthesis(string $source, int $open): int
    {
        $depth = 0;
        $quote = null;

        for ($i = $open, $length = strlen($source); $i < $length; $i++) {
            $character = $source[$i];

            if ($quote !== null) {
                if ($character === '\\') {
                    $i++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')' && --$depth === 0) {
                return $i;
            }
        }

        return $length;
    }

    /**
     * String literals of a script that read like words shown to people.
     *
     * @return list<string>
     */
    private function scriptTexts(string $source): array
    {
        $source = preg_replace(['/\/\*.*?\*\//s', '/(?<![:\'"\\\\])\/\/[^\n]*/'], ' ', $source) ?? '';
        $texts = [];

        foreach (preg_split('/\n/', $source) ?: [] as $line) {
            if (preg_match('/^\s*(?:import\b|export\s+\*|console\.|throw\s+new\s+Error\()|\bnew\s+(?:DOMException|Error|TypeError)\(|\bfrom\s+[\'"]|\brequire\(/', $line)) {
                continue;
            }

            preg_match_all('/\'((?:[^\'\\\\\n]|\\\\.)*)\'|"((?:[^"\\\\\n]|\\\\.)*)"|`((?:[^`\\\\]|\\\\.)*)`/', $line, $literals, PREG_SET_ORDER);

            foreach ($literals as $literal) {
                $value = $literal[3] ?? $literal[2] ?? $literal[1];
                $value = preg_replace('/\$\{[^}]*\}/', ' ', $value) ?? '';

                if ($this->isScriptText($value, $line)) {
                    $texts[] = trim($value);
                }
            }
        }

        return $texts;
    }

    private function isScriptText(string $value, string $line): bool
    {
        if (preg_match('/^[\s.#\[]|^[a-z][\w-]*:\/\/|^\/|[<>{}=;]|\$\(|\b(?:var|calc|rgba?)\(/', $value)) {
            return false;
        }

        $value = $this->withoutNeutralWords($value);

        if (preg_match('/[À-ÖØ-öø-ÿЀ-ӿ]/u', $value) && preg_match('/\p{L}{2}/u', $value)) {
            return true;
        }

        if (preg_match('/^[A-Z][a-z]+(?:\s+[A-Za-z][a-z]*){1,}[\s.!?:…]*$/', $value)) {
            return true;
        }

        return preg_match('/(?:textContent|innerText|title|placeholder|ariaLabel)\s*=|\b(?:alert|confirm|prompt|showToast)\(|setAttribute\(\s*[\'"](?:title|aria-label|placeholder|alt)/', $line) === 1
            && preg_match('/^[A-Z][a-z]{2,}/', $value) === 1;
    }

    private function isText(string $value): bool
    {
        $value = html_entity_decode(preg_replace('/&[a-z]+;|&#\d+;/i', ' ', $value) ?? '', ENT_QUOTES);
        $value = $this->withoutNeutralWords($value);

        return preg_match('/\p{L}{2,}/u', $value) === 1;
    }

    private function withoutNeutralWords(string $value): string
    {
        return str_replace(self::NEUTRAL_WORDS, ' ', $value);
    }

    /**
     * @return list<SplFileInfo>
     */
    private function scripts(): array
    {
        $files = array_filter(
            File::allFiles(resource_path('js')),
            fn (SplFileInfo $file) => in_array($file->getExtension(), ['js', 'ts'], true),
        );

        return [...array_values($files), ...array_values(array_filter(File::files(public_path()), fn (SplFileInfo $file) => $file->getExtension() === 'js'))];
    }

    private function relative(SplFileInfo $file): string
    {
        return str_replace(base_path().'/', '', $file->getPathname());
    }
}
