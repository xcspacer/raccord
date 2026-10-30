<?php

namespace App\Http\Requests\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\PhotoAnnotations;
use App\Models\AuditPhoto;
use App\Models\AuditPhotoAnnotation;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The shapes the person draws over a photo (none clears theirs); the service checks each point and keeps only the known
 * keys of well-formed shapes.
 */
class SavePhotoAnnotationRequest extends FormRequest
{
    public function authorize(): Response
    {
        $photo = $this->route('photo');

        return $photo instanceof AuditPhoto ? Gate::forUser($this->user())->inspect('annotate', [AuditPhotoAnnotation::class, $photo]) : Response::denyAsNotFound();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'shapes' => ['present', 'array', 'max:'.PhotoAnnotations::MAX_SHAPES],
            'shapes.*' => ['array'],
            'shapes.*.type' => ['required', Rule::in(PhotoAnnotations::TYPES)],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function shapes(): array
    {
        return array_values(array_filter((array) $this->input('shapes', []), 'is_array'));
    }
}
