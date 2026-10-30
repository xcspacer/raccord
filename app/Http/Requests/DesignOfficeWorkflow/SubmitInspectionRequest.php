<?php

namespace App\Http\Requests\DesignOfficeWorkflow;

use App\Enums\AuditFolder;
use App\Models\AuditBox;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Submission of the inspection of a box by its responsible technician: the operation id of the phone (a resent
 * submission is a repeat) and a written justification for each mandatory folder that could not be photographed.
 */
class SubmitInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $box = $this->route('box');

        return $box instanceof AuditBox && $this->user()?->can('submit', $box) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operation_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'justifications' => ['sometimes', 'array', 'max:20'],
            'justifications.*' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string> Folder value => justification, unknown folders left out.
     */
    public function justifications(): array
    {
        $folders = array_map(fn (AuditFolder $folder) => $folder->value, AuditFolder::cases());

        return collect((array) $this->validated('justifications', []))
            ->filter(fn (mixed $text, mixed $folder) => in_array($folder, $folders, true) && is_string($text))
            ->all();
    }
}
