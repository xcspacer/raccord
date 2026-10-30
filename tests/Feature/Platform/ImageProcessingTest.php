<?php

namespace Tests\Feature\Platform;

use App\Actions\Files\ProcessImage;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Photos are re-encoded on the server; a picture announcing too many pixels is refused before it is decoded, so a
 * small file cannot exhaust the memory (UPL-03, TST-08).
 */
class ImageProcessingTest extends TestCase
{
    public function test_an_image_announcing_too_many_pixels_is_refused_before_decoding(): void
    {
        $header = pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$header.pack('N', crc32('IHDR'.$header)).pack('N', 0).'IEND'.pack('N', crc32('IEND'));
        $file = UploadedFile::fake()->createWithContent('bomb.png', $png);

        $this->assertSame([20000, 20000], array_slice((array) getimagesize($file->getRealPath()), 0, 2));
        $this->assertGreaterThan(ProcessImage::MAX_PIXELS, 20000 * 20000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image too large');

        app(ProcessImage::class)->handle($file);
    }

    public function test_a_regular_photo_is_re_encoded(): void
    {
        $result = app(ProcessImage::class)->handle(UploadedFile::fake()->image('photo.jpg', 3000, 2000));

        $this->assertSame([2560, 1707], [$result['width'], $result['height']]);
        $this->assertStringStartsWith("\xFF\xD8", $result['jpeg']);
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ProcessImage::class)->handle(UploadedFile::fake()->createWithContent('photo.jpg', '<html><script>alert(1)</script></html>'));
    }
}
