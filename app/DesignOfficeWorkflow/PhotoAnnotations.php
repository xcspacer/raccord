<?php

namespace App\DesignOfficeWorkflow;

use App\Models\AuditPhoto;
use App\Models\AuditPhotoAnnotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Drawings of the design office and the supervisors over a photo (circle, arrow, free line, text), kept apart from the
 * photo: the file is never touched, and each person has one set of shapes per photo that they redraw or clear. Points
 * are fractions of the photo's width and height, so the shapes fit the photo at any size. Internal: the team of the box
 * sees them, a client never does.
 */
class PhotoAnnotations
{
    public const array TYPES = ['circle', 'arrow', 'pen', 'text'];

    public const array COLOURS = ['red', 'yellow', 'green', 'blue'];

    public const int MAX_SHAPES = 60;

    public const int MAX_POINTS = 400;

    public const int MAX_TEXT = 120;

    public function __construct(private WorkflowGuard $guard, private WorkflowJournal $journal) {}

    /**
     * Replaces the shapes of the person on the photo (none clears them).
     *
     * @param  list<array<string, mixed>>  $shapes
     *
     * @throws WorkflowException
     */
    public function save(User $author, AuditPhoto $photo, array $shapes): ?AuditPhotoAnnotation
    {
        if (! WorkflowRoles::isSupervisor($author) && ! WorkflowRoles::isReviewer($author)) {
            throw new WorkflowException('forbidden', 403);
        }

        $clean = $this->clean($shapes);

        return DB::transaction(function () use ($author, $photo, $clean): ?AuditPhotoAnnotation {
            $photo = AuditPhoto::query()->with('audit')->lockForUpdate()->findOrFail($photo->id);
            $this->guard->ensureOpen($photo->audit);

            $annotation = AuditPhotoAnnotation::query()->where('audit_photo_id', $photo->id)->where('author_id', $author->id)->first();

            if ($clean === []) {
                $annotation?->delete();
                $this->journal->record('photo_annotation_cleared', $photo, $author, ['photo_id' => $photo->id]);

                return null;
            }

            $annotation ??= new AuditPhotoAnnotation(['audit_photo_id' => $photo->id, 'author_id' => $author->id]);
            $annotation->shapes = $clean;
            $annotation->save();
            $this->journal->record('photo_annotated', $photo, $author, ['photo_id' => $photo->id, 'shapes' => count($clean)]);

            return $annotation;
        });
    }

    /**
     * Every set of shapes drawn on the photo, with its author.
     *
     * @return list<array{id: int, author_id: int, by: string, at: string|null, shapes: list<array<string, mixed>>}>
     */
    public function of(AuditPhoto $photo): array
    {
        return AuditPhotoAnnotation::query()->where('audit_photo_id', $photo->id)->with('author:id,name')->orderBy('id')->get()
            ->map(fn (AuditPhotoAnnotation $annotation) => [
                'id' => $annotation->id, 'author_id' => $annotation->author_id, 'by' => $annotation->author->name,
                'at' => $annotation->updated_at?->toIso8601String(), 'shapes' => $annotation->shapes,
            ])->values()->all();
    }

    /**
     * Only the known keys of well-formed shapes, with points inside the photo.
     *
     * @param  list<array<string, mixed>>  $shapes
     * @return list<array<string, mixed>>
     *
     * @throws WorkflowException
     */
    private function clean(array $shapes): array
    {
        if (count($shapes) > self::MAX_SHAPES) {
            throw new WorkflowException('annotation_invalid');
        }

        $clean = [];

        foreach ($shapes as $shape) {
            $type = $shape['type'] ?? null;
            $colour = in_array($shape['colour'] ?? null, self::COLOURS, true) ? $shape['colour'] : self::COLOURS[0];

            $clean[] = match ($type) {
                'circle', 'arrow' => ['type' => $type, 'colour' => $colour, 'x1' => $this->fraction($shape['x1'] ?? null), 'y1' => $this->fraction($shape['y1'] ?? null), 'x2' => $this->fraction($shape['x2'] ?? null), 'y2' => $this->fraction($shape['y2'] ?? null)],
                'pen' => ['type' => $type, 'colour' => $colour, 'points' => $this->points($shape['points'] ?? null)],
                'text' => ['type' => $type, 'colour' => $colour, 'x' => $this->fraction($shape['x'] ?? null), 'y' => $this->fraction($shape['y'] ?? null), 'text' => $this->text($shape['text'] ?? null)],
                default => throw new WorkflowException('annotation_invalid'),
            };
        }

        return $clean;
    }

    /**
     * @throws WorkflowException
     */
    private function fraction(mixed $value): float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw new WorkflowException('annotation_invalid');
        }

        return round(min(1.0, max(0.0, (float) $value)), 4);
    }

    /**
     * @return list<array{0: float, 1: float}>
     *
     * @throws WorkflowException
     */
    private function points(mixed $points): array
    {
        if (! is_array($points) || count($points) < 2 || count($points) > self::MAX_POINTS) {
            throw new WorkflowException('annotation_invalid');
        }

        return array_values(array_map(fn (mixed $point) => is_array($point) && count($point) === 2
            ? [$this->fraction(array_values($point)[0]), $this->fraction(array_values($point)[1])]
            : throw new WorkflowException('annotation_invalid'), $points));
    }

    /**
     * @throws WorkflowException
     */
    private function text(mixed $text): string
    {
        $text = is_string($text) ? trim($text) : '';

        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            throw new WorkflowException('annotation_invalid');
        }

        return $text;
    }
}
