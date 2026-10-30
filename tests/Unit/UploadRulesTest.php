<?php

namespace Tests\Unit;

use App\Actions\Files\UploadRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SampleFiles;
use Tests\TestCase;
use ZipArchive;

/**
 * Each upload field accepts only its own file types (D10), recognised by their content.
 */
class UploadRulesTest extends TestCase
{
    private static function image(string $name, string $type): UploadedFile
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        match ($type) {
            'png' => imagepng($image),
            'webp' => imagewebp($image),
            'gif' => imagegif($image),
            default => imagejpeg($image),
        };

        return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
    }

    /**
     * @param  array<string, string>  $entries
     */
    private static function zip(string $name, array $entries): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }

        $zip->close();
        $contents = (string) file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private static function withMacros(string $name): UploadedFile
    {
        $workbook = SampleFiles::xlsx($name);
        $zip = new ZipArchive;
        $zip->open($workbook->getRealPath());
        $zip->addFromString('xl/vbaProject.bin', 'macro');
        $zip->close();

        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($workbook->getRealPath()));
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function passes(UploadedFile $file, array $rules): bool
    {
        return Validator::make(['file' => $file], ['file' => $rules])->passes();
    }

    /**
     * @return array<string, array{0: list<string>, 1: \Closure(): UploadedFile}>
     */
    public static function accepted(): array
    {
        return [
            'photo jpeg' => [UploadRules::PHOTO, fn () => self::image('photo.jpg', 'jpeg')],
            'photo png sent under a jpeg name' => [UploadRules::PHOTO, fn () => self::image('photo.jpg', 'png')],
            'photo webp' => [UploadRules::PHOTO, fn () => self::image('photo.webp', 'webp')],
            'personal document pdf' => [UploadRules::PERSONAL_DOCUMENT, fn () => SampleFiles::pdf('card.pdf')],
            'personal document jpeg' => [UploadRules::PERSONAL_DOCUMENT, fn () => self::image('card.jpeg', 'jpeg')],
            'map kmz' => [UploadRules::MAP, fn () => SampleFiles::kmz()],
            'map kml' => [UploadRules::MAP, fn () => SampleFiles::kml()],
            'audit synoptic pdf' => [UploadRules::SYNOPTIC, fn () => SampleFiles::pdf('syno.pdf')],
            'audit synoptic text' => [UploadRules::SYNOPTIC, fn () => SampleFiles::text('syno.csv', "BPE-1;PBO\n")],
            'work site synoptic spreadsheet' => [UploadRules::WORK_SITE_SYNOPTIC, fn () => SampleFiles::xlsx('syno.xlsx')],
            'plan xlsx' => [UploadRules::PLAN, fn () => SampleFiles::xlsx('BPE-1.xlsx')],
            'plan pdf' => [UploadRules::PLAN, fn () => SampleFiles::pdf('BPE-1.pdf')],
            'logo png' => [UploadRules::LOGO, fn () => self::image('logo.png', 'png')],
            'equipment list markdown' => [UploadRules::TEXT_LIST, fn () => SampleFiles::text('list.md', "- drill\n")],
        ];
    }

    /**
     * @return array<string, array{0: list<string>, 1: \Closure(): UploadedFile}>
     */
    public static function refused(): array
    {
        $html = '<html><body><script>alert(1)</script></body></html>';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        return [
            'photo: web page renamed .jpg' => [UploadRules::PHOTO, fn () => UploadedFile::fake()->createWithContent('photo.jpg', $html)],
            'photo: svg renamed .png' => [UploadRules::PHOTO, fn () => UploadedFile::fake()->createWithContent('photo.png', $svg)],
            'photo: gif' => [UploadRules::PHOTO, fn () => self::image('photo.gif', 'gif')],
            'photo: pdf' => [UploadRules::PHOTO, fn () => SampleFiles::pdf('photo.pdf')],
            'personal document: web page renamed .pdf' => [UploadRules::PERSONAL_DOCUMENT, fn () => UploadedFile::fake()->createWithContent('card.pdf', $html)],
            'personal document: image renamed .pdf' => [UploadRules::PERSONAL_DOCUMENT, fn () => self::image('card.pdf', 'png')],
            'personal document: word document' => [UploadRules::PERSONAL_DOCUMENT, fn () => self::zip('card.docx', ['word/document.xml' => '<w:document/>'])],
            'map: archive without a map' => [UploadRules::MAP, fn () => self::zip('map.kmz', ['readme.txt' => 'nothing'])],
            'map: pdf renamed .kmz' => [UploadRules::MAP, fn () => SampleFiles::pdf('map.kmz')],
            'map: web page renamed .kml' => [UploadRules::MAP, fn () => UploadedFile::fake()->createWithContent('map.kml', $html)],
            'audit synoptic: web page renamed .txt' => [UploadRules::SYNOPTIC, fn () => UploadedFile::fake()->createWithContent('syno.txt', $html)],
            'audit synoptic: image' => [UploadRules::SYNOPTIC, fn () => self::image('syno.png', 'png')],
            'work site synoptic: zip' => [UploadRules::WORK_SITE_SYNOPTIC, fn () => self::zip('syno.zip', ['a.pdf' => '%PDF-1.4'])],
            'work site synoptic: word document' => [UploadRules::WORK_SITE_SYNOPTIC, fn () => self::zip('syno.docx', ['word/document.xml' => '<w:document/>'])],
            'work site synoptic: script' => [UploadRules::WORK_SITE_SYNOPTIC, fn () => UploadedFile::fake()->createWithContent('syno.php', '<?php echo 1;')],
            'plan: workbook with macros renamed .xlsx' => [UploadRules::PLAN, fn () => self::withMacros('BPE-1.xlsx')],
            'plan: macro-enabled workbook' => [UploadRules::PLAN, fn () => self::withMacros('BPE-1.xlsm')],
            'plan: zip renamed .xlsx' => [UploadRules::PLAN, fn () => self::zip('BPE-1.xlsx', ['a.txt' => 'x'])],
            'logo: svg' => [UploadRules::LOGO, fn () => UploadedFile::fake()->createWithContent('logo.svg', $svg)],
            'logo: svg renamed .png' => [UploadRules::LOGO, fn () => UploadedFile::fake()->createWithContent('logo.png', $svg)],
            'equipment list: web page renamed .txt' => [UploadRules::TEXT_LIST, fn () => UploadedFile::fake()->createWithContent('list.txt', $html)],
            'any field: file without extension' => [UploadRules::WORK_SITE_SYNOPTIC, fn () => SampleFiles::pdf('syno')],
        ];
    }

    /**
     * @param  list<string>  $types
     * @param  \Closure(): UploadedFile  $file
     */
    #[DataProvider('accepted')]
    public function test_the_types_of_the_field_are_accepted(array $types, \Closure $file): void
    {
        $rules = $types === UploadRules::PHOTO ? UploadRules::photo(1024) : UploadRules::document($types, 1024);

        $this->assertTrue($this->passes($file(), $rules));
    }

    /**
     * @param  list<string>  $types
     * @param  \Closure(): UploadedFile  $file
     */
    #[DataProvider('refused')]
    public function test_other_types_are_refused_whatever_their_name(array $types, \Closure $file): void
    {
        $rules = $types === UploadRules::PHOTO ? UploadRules::photo(1024) : UploadRules::document($types, 1024);

        $this->assertFalse($this->passes($file(), $rules));
    }

    public function test_the_refusal_names_the_accepted_types(): void
    {
        $validator = Validator::make(['card' => SampleFiles::text('card.txt')], ['card' => UploadRules::document(UploadRules::PERSONAL_DOCUMENT, 1024)]);

        $this->assertSame(__('validation.mimes', ['attribute' => 'card', 'values' => 'pdf, png, jpg, jpeg']), $validator->errors()->first('card'));
    }
}
