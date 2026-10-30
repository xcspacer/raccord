<?php

namespace App\Expenses;

use App\Actions\Files\ProcessImage;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Finds the receipts lying on a darker surface in one photo and cuts each of them out. Receipts are the light,
 * hardly coloured areas (Otsu threshold on the grey level, low saturation); touching receipts are told apart by
 * eroding the mask before labelling it. Pieces come back column by column, each column top to bottom. When
 * nothing convincing is found, or a single area covers almost the whole photo, the photo is kept as it is.
 */
class ReceiptCropper
{
    private const int ANALYSIS_SIDE = 600;

    private const float MAX_SATURATION = 0.28;

    private const float MIN_AREA_SHARE = 0.012;

    private const float MIN_SIDE_SHARE = 0.06;

    private const float WHOLE_PHOTO_SHARE = 0.88;

    private const float MARGIN = 0.012;

    private const int PIECE_SIDE = 1600;

    private const int PIECE_QUALITY = 80;

    public function __construct(private ProcessImage $images) {}

    /**
     * @return list<string> JPEG contents of the pieces; a single entry is the whole photo.
     *
     * @throws InvalidArgumentException When the file is not a readable image.
     */
    public function pieces(UploadedFile $file): array
    {
        $photo = $this->images->handle($file);
        $source = @imagecreatefromstring($photo['jpeg']);

        if ($source === false) {
            throw new InvalidArgumentException('Not an image');
        }

        $boxes = $this->boxes($source);

        if (count($boxes) === 0) {
            return [$photo['jpeg']];
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $pieces = [];

        foreach ($boxes as [$left, $top, $right, $bottom]) {
            $marginX = (int) round($width * self::MARGIN);
            $marginY = (int) round($height * self::MARGIN);
            $x = max(0, (int) floor($left * $width) - $marginX);
            $y = max(0, (int) floor($top * $height) - $marginY);
            $cropped = imagecrop($source, ['x' => $x, 'y' => $y, 'width' => min($width, (int) ceil($right * $width) + $marginX) - $x, 'height' => min($height, (int) ceil($bottom * $height) + $marginY) - $y]);

            if ($cropped !== false) {
                $pieces[] = $this->encode($cropped);
            }
        }

        return $pieces === [] ? [$photo['jpeg']] : $pieces;
    }

    /**
     * Areas of the receipts as fractions of the photo: [left, top, right, bottom], in reading order. Empty when
     * the whole photo should be kept.
     *
     * @return list<array{float, float, float, float}>
     */
    private function boxes(\GdImage $source): array
    {
        $scale = min(1, self::ANALYSIS_SIDE / max(imagesx($source), imagesy($source)));
        $small = imagescale($source, max(1, (int) round(imagesx($source) * $scale)), max(1, (int) round(imagesy($source) * $scale)));

        if ($small === false) {
            return [];
        }

        imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        $width = imagesx($small);
        $height = imagesy($small);

        [$grey, $saturation] = $this->channels($small, $width, $height);
        $threshold = $this->otsu($grey);

        $mask = [];

        foreach ($grey as $index => $value) {
            $mask[$index] = $value > $threshold && $saturation[$index] < self::MAX_SATURATION ? 1 : 0;
        }

        $radius = max(2, (int) round(max($width, $height) * 0.012));
        $eroded = $this->erode($mask, $width, $height, $radius);
        $boxes = [];

        foreach ($this->components($eroded, $width, $height) as $component) {
            $left = max(0, $component['left'] - $radius);
            $top = max(0, $component['top'] - $radius);
            $right = min($width - 1, $component['right'] + $radius);
            $bottom = min($height - 1, $component['bottom'] + $radius);

            if ($component['area'] < self::MIN_AREA_SHARE * $width * $height
                || ($right - $left + 1) < self::MIN_SIDE_SHARE * $width
                || ($bottom - $top + 1) < self::MIN_SIDE_SHARE * $height) {
                continue;
            }

            $boxes[] = [$left / $width, $top / $height, ($right + 1) / $width, ($bottom + 1) / $height];
        }

        if ($boxes === [] || (count($boxes) === 1 && ($boxes[0][2] - $boxes[0][0]) * ($boxes[0][3] - $boxes[0][1]) > self::WHOLE_PHOTO_SHARE)) {
            return [];
        }

        return $this->readingOrder($boxes);
    }

    /**
     * @return array{list<int>, list<float>}
     */
    private function channels(\GdImage $image, int $width, int $height): array
    {
        $grey = [];
        $saturation = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $red = ($rgb >> 16) & 0xFF;
                $green = ($rgb >> 8) & 0xFF;
                $blue = $rgb & 0xFF;
                $max = max($red, $green, $blue);
                $grey[] = (int) round(0.299 * $red + 0.587 * $green + 0.114 * $blue);
                $saturation[] = $max === 0 ? 0.0 : ($max - min($red, $green, $blue)) / $max;
            }
        }

        return [$grey, $saturation];
    }

    /**
     * @param  list<int>  $grey
     */
    private function otsu(array $grey): int
    {
        $histogram = array_fill(0, 256, 0);

        foreach ($grey as $value) {
            $histogram[$value]++;
        }

        $total = count($grey);
        $sum = 0;

        foreach ($histogram as $level => $count) {
            $sum += $level * $count;
        }

        $weightBackground = 0;
        $sumBackground = 0;
        $best = 0.0;
        $threshold = 127;

        foreach ($histogram as $level => $count) {
            $weightBackground += $count;

            if ($weightBackground === 0) {
                continue;
            }

            $weightForeground = $total - $weightBackground;

            if ($weightForeground === 0) {
                break;
            }

            $sumBackground += $level * $count;
            $meanDifference = $sumBackground / $weightBackground - ($sum - $sumBackground) / $weightForeground;
            $between = $weightBackground * $weightForeground * $meanDifference ** 2;

            if ($between > $best) {
                $best = $between;
                $threshold = $level;
            }
        }

        return $threshold;
    }

    /**
     * Keeps the pixels whose whole square neighbourhood is part of the mask (summed-area table).
     *
     * @param  list<int>  $mask
     * @return list<int>
     */
    private function erode(array $mask, int $width, int $height, int $radius): array
    {
        $stride = $width + 1;
        $integral = array_fill(0, $stride * ($height + 1), 0);

        for ($y = 0; $y < $height; $y++) {
            $row = 0;

            for ($x = 0; $x < $width; $x++) {
                $row += $mask[$y * $width + $x];
                $integral[($y + 1) * $stride + $x + 1] = $integral[$y * $stride + $x + 1] + $row;
            }
        }

        $side = 2 * $radius + 1;
        $eroded = array_fill(0, $width * $height, 0);

        for ($y = $radius; $y < $height - $radius; $y++) {
            for ($x = $radius; $x < $width - $radius; $x++) {
                $sum = $integral[($y + $radius + 1) * $stride + $x + $radius + 1] - $integral[($y - $radius) * $stride + $x + $radius + 1]
                    - $integral[($y + $radius + 1) * $stride + $x - $radius] + $integral[($y - $radius) * $stride + $x - $radius];

                if ($sum === $side * $side) {
                    $eroded[$y * $width + $x] = 1;
                }
            }
        }

        return $eroded;
    }

    /**
     * Connected areas (4 neighbours) of the mask.
     *
     * @param  list<int>  $mask
     * @return list<array{area: int, left: int, top: int, right: int, bottom: int}>
     */
    private function components(array $mask, int $width, int $height): array
    {
        $components = [];

        for ($start = 0, $count = $width * $height; $start < $count; $start++) {
            if ($mask[$start] !== 1) {
                continue;
            }

            $mask[$start] = 2;
            $stack = [$start];
            $component = ['area' => 0, 'left' => $width, 'top' => $height, 'right' => 0, 'bottom' => 0];

            while ($stack !== []) {
                $index = array_pop($stack);
                $x = $index % $width;
                $y = intdiv($index, $width);
                $component['area']++;
                $component['left'] = min($component['left'], $x);
                $component['right'] = max($component['right'], $x);
                $component['top'] = min($component['top'], $y);
                $component['bottom'] = max($component['bottom'], $y);

                foreach ([[$x - 1, $y], [$x + 1, $y], [$x, $y - 1], [$x, $y + 1]] as [$nx, $ny]) {
                    if ($nx >= 0 && $nx < $width && $ny >= 0 && $ny < $height && $mask[$ny * $width + $nx] === 1) {
                        $mask[$ny * $width + $nx] = 2;
                        $stack[] = $ny * $width + $nx;
                    }
                }
            }

            $components[] = $component;
        }

        return $components;
    }

    /**
     * Column by column (left to right), each column from top to bottom. A box belongs to a column when its
     * centre lies within the horizontal span of the first box of that column.
     *
     * @param  list<array{float, float, float, float}>  $boxes
     * @return list<array{float, float, float, float}>
     */
    private function readingOrder(array $boxes): array
    {
        usort($boxes, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $columns = [];

        foreach ($boxes as $box) {
            $centre = ($box[0] + $box[2]) / 2;
            $target = null;

            foreach ($columns as $index => $column) {
                if ($centre >= $column[0][0] && $centre <= $column[0][2]) {
                    $target = $index;

                    break;
                }
            }

            if ($target === null) {
                $columns[] = [$box];
            } else {
                $columns[$target][] = $box;
            }
        }

        $ordered = [];

        foreach ($columns as $column) {
            usort($column, fn (array $a, array $b) => $a[1] <=> $b[1]);
            array_push($ordered, ...$column);
        }

        return $ordered;
    }

    private function encode(\GdImage $image): string
    {
        $ratio = min(1, self::PIECE_SIDE / max(imagesx($image), imagesy($image)));

        if ($ratio < 1) {
            $image = imagescale($image, max(1, (int) round(imagesx($image) * $ratio)), max(1, (int) round(imagesy($image) * $ratio))) ?: $image;
        }

        ob_start();
        imagejpeg($image, null, self::PIECE_QUALITY);

        return (string) ob_get_clean();
    }
}
