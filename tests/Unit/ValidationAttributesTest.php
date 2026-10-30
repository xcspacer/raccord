<?php

namespace Tests\Unit;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A validation message names the field it is about (« Le champ :attribute est obligatoire »). Without a translated
 * name the technical one shows (« agency id »), so every validated field has one: in the `attributes` of
 * lang/{locale}/validation.php, or in the validation attributes of its own component.
 */
class ValidationAttributesTest extends TestCase
{
    private const string RULE = "(?:'(?:required|nullable|sometimes|string|integer|boolean|array|numeric|date|email|url|file|image|present|accepted|filled|bail|max|min|between|in|regex|date_format|mimes|size|after|before|different|same|confirmed|alpha_dash|alpha_num|digits|exists|unique|current_password)(?:[:|][^']*)?'(?!\\s*=>)|Rule::|UploadRules::|Password::)";

    public function test_every_validated_field_has_a_translated_name(): void
    {
        $missing = [];

        foreach ($this->validatedFields() as $file => $fields) {
            preg_match('/function validationAttributes\(\).*?return \[(.*?)\];/s', (string) file_get_contents($file), $own);

            foreach ($fields as $field) {
                if (isset($own[1]) && preg_match("/'".preg_quote($field, '/')."'\s*=>\s*__\(/", $own[1])) {
                    continue;
                }

                foreach (config('app.supported_locales') as $locale) {
                    if (! $this->hasTranslatedName($field, $locale)) {
                        $missing[] = "{$field} ({$locale}) in ".str_replace(base_path().'/', '', $file);
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Validated fields without a translated name');
    }

    public function test_messages_name_the_field_in_the_user_language(): void
    {
        app()->setLocale('fr');

        $errors = validator(['agencyId' => null, 'rows' => [['label' => '']]], ['agencyId' => 'required', 'rows.*.label' => 'required'])->errors();

        $this->assertSame('Le champ agence est obligatoire.', $errors->first('agencyId'));
        $this->assertSame('Le champ désignation est obligatoire.', $errors->first('rows.0.label'));
    }

    /**
     * Looks the name up as the validator does: the exact key, else a key with `*` segments matching it.
     */
    private function hasTranslatedName(string $field, string $locale): bool
    {
        $names = Arr::dot((array) trans('validation.attributes', [], $locale));

        if (isset($names[$field])) {
            return true;
        }

        foreach (array_keys($names) as $name) {
            if (str_contains($name, '*') && preg_match('#^'.str_replace('\\*', '([^.]*)', preg_quote($name, '#')).'\z#u', $field) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fields validated by the screens and endpoints: keys whose value is a list of rules, and #[Validate] properties.
     *
     * @return array<string, list<string>>
     */
    private function validatedFields(): array
    {
        $pattern = "/'([A-Za-z][\w.*]*)'\s*=>\s*(?:\[\s*(?:[^\]\[]|\[[^\]]*\])*?".self::RULE.'|'.self::RULE.'|\$\w+\s*\?\s*\[|\[\.\.\.\$this->\w+Rules)/';
        $files = [...File::allFiles(app_path('Livewire')), ...File::allFiles(app_path('Http')), ...File::allFiles(app_path('Actions/Fortify'))];
        $fields = [];

        foreach ($files as $file) {
            $source = $file->getContents();

            if (! preg_match('/validate|Validator::make|validator\(|function rules\(/', $source)) {
                continue;
            }

            preg_match_all($pattern, $source, $rules);
            preg_match_all('/#\[Validate\([^\]]*\)\]\s*public\s+[?\w|]+\s+\$(\w+)/', $source, $properties);

            $found = array_values(array_unique([...$rules[1], ...$properties[1]]));

            if ($found !== []) {
                $fields[$file->getPathname()] = $found;
            }
        }

        return $fields;
    }
}
