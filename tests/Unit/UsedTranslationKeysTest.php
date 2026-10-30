<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * Every translation key written literally in the code or in a view must exist (a missing key would show
 * as raw text such as « passwords.title » on screen).
 */
class UsedTranslationKeysTest extends TestCase
{
    public function test_every_literal_translation_key_exists_in_every_locale(): void
    {
        $files = [...File::allFiles(app_path()), ...File::allFiles(resource_path('views'))];
        $missing = [];

        foreach ($files as $file) {
            preg_match_all('/(?:__|trans_choice|trans)\(\s*\'([a-z_]+(?:\.[a-z_0-9]+)+)\'\s*[,)]/', $file->getContents(), $matches);

            foreach (array_unique($matches[1]) as $key) {
                foreach (config('app.supported_locales') as $locale) {
                    if (! Lang::has($key, $locale, false)) {
                        $missing[] = "{$key} ({$locale}) in {$file->getRelativePathname()}";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Missing translation keys');
    }
}
