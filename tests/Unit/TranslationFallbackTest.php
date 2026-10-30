<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TranslationFallbackTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['en_GB' => ['en_GB'], 'fr' => ['fr'], 'es' => ['es'], 'uk' => ['uk'], 'pt' => ['pt']];
    }

    public function test_the_fallback_locale_is_british_english(): void
    {
        $this->assertSame('en_GB', config('app.fallback_locale'));
        $this->assertSame('en_GB', app('translator')->getFallback());
    }

    public function test_a_key_missing_in_a_locale_falls_back_to_british_english(): void
    {
        Lang::addLines(['fallback_probe.only_in_reference' => 'Reference text for :name'], 'en_GB');
        app()->setLocale('fr');

        $this->assertSame('Reference text for Léa', __('fallback_probe.only_in_reference', ['name' => 'Léa']));
    }

    public function test_an_unknown_key_is_returned_as_is(): void
    {
        app()->setLocale('pt');

        $this->assertSame('missing_file.unknown_key', __('missing_file.unknown_key'));
    }

    #[DataProvider('locales')]
    public function test_variables_are_substituted_in_every_locale(string $locale): void
    {
        app()->setLocale($locale);

        $text = __('pdf.expense_report.file', ['company' => 'Acme', 'name' => 'Jane Doe', 'month' => 'OCTOBER 2026']);

        $this->assertStringContainsString('Acme', $text);
        $this->assertStringContainsString('Jane Doe', $text);
        $this->assertStringContainsString('OCTOBER 2026', $text);
        $this->assertStringNotContainsString(':', $text);
    }

    public function test_capitalised_placeholders_follow_the_case_of_the_value(): void
    {
        Lang::addLines(['fallback_probe.cases' => ':name / :Name / :NAME'], 'en_GB');

        $this->assertSame('léa / Léa / LÉA', __('fallback_probe.cases', ['name' => 'léa']));
    }

    #[DataProvider('locales')]
    public function test_plural_choices_pick_the_form_and_substitute_the_count(string $locale): void
    {
        app()->setLocale($locale);

        $one = trans_choice('companies.usage', 1, ['count' => 1]);
        $many = trans_choice('companies.usage', 7, ['count' => 7]);

        $this->assertStringContainsString('1', $one);
        $this->assertStringContainsString('7', $many);
        $this->assertNotSame($one, str_replace('7', '1', $many));
    }
}
