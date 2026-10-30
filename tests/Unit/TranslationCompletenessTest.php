<?php

namespace Tests\Unit;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TranslationCompletenessTest extends TestCase
{
    private const string REFERENCE_LOCALE = 'en_GB';

    /**
     * @return array<string, array{string}>
     */
    public static function otherLocales(): array
    {
        return ['fr' => ['fr'], 'es' => ['es'], 'uk' => ['uk'], 'pt' => ['pt']];
    }

    #[DataProvider('otherLocales')]
    public function test_every_translation_file_has_the_same_keys_as_the_reference(string $locale): void
    {
        foreach ($this->referenceFiles() as $file) {
            $path = lang_path("{$locale}/{$file}.php");
            $this->assertFileExists($path, "Missing translation file {$locale}/{$file}.php");

            $referenceKeys = array_keys(Arr::dot(require lang_path(self::REFERENCE_LOCALE."/{$file}.php")));
            $localeKeys = array_keys(Arr::dot(require $path));

            $this->assertSame([], array_values(array_diff($referenceKeys, $localeKeys)), "Keys missing in {$locale}/{$file}.php");
            $this->assertSame([], array_values(array_diff($localeKeys, $referenceKeys)), "Unknown keys in {$locale}/{$file}.php");
        }
    }

    public function test_no_translation_is_empty(): void
    {
        foreach (config('app.supported_locales') as $locale) {
            foreach (glob(lang_path("{$locale}/*.php")) ?: [] as $path) {
                foreach (Arr::dot(require $path) as $key => $value) {
                    if (is_array($value)) {
                        continue;
                    }

                    $this->assertNotSame('', trim((string) $value), "Empty translation {$locale}: {$key}");
                }
            }
        }
    }

    public function test_every_enum_value_has_a_translated_label(): void
    {
        foreach (glob(app_path('Enums/*.php')) ?: [] as $path) {
            $enum = 'App\\Enums\\'.basename($path, '.php');

            if (! method_exists($enum, 'label')) {
                continue;
            }

            foreach (config('app.supported_locales') as $locale) {
                app()->setLocale($locale);

                foreach ($enum::cases() as $case) {
                    $this->assertStringNotContainsString('.', $case->label(), "Untranslated label {$enum}::{$case->name} in {$locale}");
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function referenceFiles(): array
    {
        return array_map(
            fn (string $path) => basename($path, '.php'),
            glob(lang_path(self::REFERENCE_LOCALE.'/*.php')) ?: [],
        );
    }
}
