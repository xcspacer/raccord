<?php

namespace App\Expenses;

use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;

/**
 * Perceptual fingerprint (16 × 16 difference hash, 256 bits) of a ticket photo, to spot the same ticket
 * photographed twice.
 */
class PhotoHash
{
    public const int DUPLICATE_DISTANCE = 8;

    public function of(StoredFile $file): ?string
    {
        $contents = Storage::disk($file->disk)->get($file->path);
        $image = $contents === null ? false : @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        $small = imagescale($image, 17, 16, IMG_BILINEAR_FIXED);

        if ($small === false) {
            return null;
        }

        $bits = '';

        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $bits .= $this->grey($small, $x, $y) > $this->grey($small, $x + 1, $y) ? '1' : '0';
            }
        }

        return implode('', array_map(fn (string $nibble) => dechex(bindec($nibble)), str_split($bits, 4)));
    }

    public function distance(string $a, string $b): int
    {
        $distance = 0;

        foreach (str_split($a) as $index => $nibble) {
            $distance += substr_count(decbin(hexdec($nibble) ^ hexdec($b[$index] ?? '0')), '1');
        }

        return $distance;
    }

    private function grey(\GdImage $image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, $x, $y);

        return 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
    }
}
