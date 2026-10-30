<?php

namespace App\Ai;

use App\Enums\AiFeature;
use App\Enums\VehicleDamageSeverity;
use App\Models\User;
use App\Models\VehiclePhoto;
use Illuminate\Support\Facades\Storage;

/**
 * Compares the previous and the new photo of the same vehicle angle and reports a new damage, if any.
 * The result is kept on the replaced photo. The identity stamp the in-app camera prints at the bottom of the
 * photos (name, address, coordinates, date) is cut off before they are sent.
 */
class VehiclePhotoComparison
{
    /**
     * How sure the AI is of its answer.
     */
    private const array CONFIDENCES = ['high', 'medium', 'low'];

    private const int STAMP_LINES = 5;

    private const int JPEG_QUALITY = 82;

    public function __construct(private AnthropicClient $client, private AiQuota $quota) {}

    /**
     * @return array{damage_detected: bool, description: string, severity: string, confidence: string}|null Null when nothing was compared.
     */
    public function compare(User $user, VehiclePhoto $before, VehiclePhoto $after): ?array
    {
        if (! $this->client->isConfigured()) {
            return null;
        }

        try {
            $this->quota->consume($user, AiFeature::CompareVehiclePhotos);

            $data = $this->client->askForJson($this->prompt($user), [$this->image($before), $this->image($after)], 350);
        } catch (AiException $exception) {
            $before->update(['comparison' => ['damage_detected' => false, 'error' => $exception->reason]]);

            return null;
        }

        $result = [
            'damage_detected' => (bool) ($data['damage_detected'] ?? false),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 300),
            'severity' => (VehicleDamageSeverity::tryFrom((string) ($data['severity'] ?? '')) ?? VehicleDamageSeverity::None)->value,
            'confidence' => in_array($data['confidence'] ?? '', self::CONFIDENCES, true) ? $data['confidence'] : 'medium',
        ];

        $before->update(['comparison' => $result]);

        return $result;
    }

    /**
     * @return array{data: string, media_type: string}
     *
     * @throws AiException
     */
    private function image(VehiclePhoto $photo): array
    {
        $file = $photo->file;
        $contents = Storage::disk($file->disk)->get($file->path);

        if ($contents === null || strlen($contents) > 6 * 1024 * 1024) {
            throw new AiException('invalid_image');
        }

        return ['data' => base64_encode($this->withoutStamp($contents)), 'media_type' => 'image/jpeg'];
    }

    /**
     * The photo without the band holding the stamp.
     *
     * @throws AiException
     */
    private function withoutStamp(string $contents): string
    {
        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            throw new AiException('invalid_image');
        }

        $width = imagesx($image);
        $kept = imagesy($image) - self::stampHeight($width);
        $cropped = $kept >= imagesy($image) / 2 ? imagecrop($image, ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $kept]) : false;

        if ($cropped === false) {
            throw new AiException('invalid_image');
        }

        ob_start();
        imagejpeg($cropped, null, self::JPEG_QUALITY);

        return (string) ob_get_clean();
    }

    /**
     * Height of the tallest stamp (five lines) the camera prints on a photo of this width, with the geometry of
     * drawStamp() in resources/js/components/geo-camera.js, plus a small margin.
     */
    public static function stampHeight(int $width): int
    {
        $size = max(14, (int) round($width / 46));
        $padding = (int) round($size * 0.7);

        return (int) ceil($padding * 2 + self::STAMP_LINES * $size * 1.35) + 2;
    }

    /**
     * The description is written in the language of the person who took the photos, who is the one shown the alert.
     */
    private function prompt(User $user): string
    {
        $language = __('vehicle_inspection.ai_language', [], $user->locale ?: app()->getLocale());

        return <<<PROMPT
Here are two photos of the same vehicle, taken from the same angle one month apart: the first (image 1) is the old photo, the second (image 2) is the new one.
Compare them and look for any DAMAGE that appeared between the two (new scratch, dent, impact, crack, broken or missing part, unusual dirt or damage). Ignore differences in light, slight angle, weather or normal cleanliness.
Answer ONLY with a JSON object, no text around it:
{"damage_detected":true|false,"description":"...","severity":"none|minor|severe","confidence":"high|medium|low"}
- damage_detected: true only if you see damage that is clearly new on image 2 and absent from image 1.
- description: briefly describe what you see (location, type of damage), in the language of the person looking at the report: {$language}. Empty string if no damage.
- severity: none if nothing, minor for a minor scratch or dent, severe for important damage (broken window, torn-off bumper, etc.).
- confidence: low if you are not sure.
PROMPT;
    }
}
