<?php

namespace App\Http\Requests\DesignOfficeWorkflow;

use App\Models\AuditOccurrence;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Correction of an occurrence: the action done, the « after » photos and, optionally, the checks done and
 * observations.
 */
class CorrectOccurrenceRequest extends FormRequest
{
    /**
     * Refused as the policy says (an audit that is not the person's does not exist for them).
     */
    public function authorize(): Response
    {
        $subject = $this->route('occurrence');

        return $subject instanceof AuditOccurrence ? Gate::forUser($this->user())->inspect('update', $subject) : Response::denyAsNotFound();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['nullable', 'string', 'max:2000'],
            'after_photo_ids' => ['sometimes', 'array', 'max:50'],
            'after_photo_ids.*' => ['integer', 'min:1'],
            'checks' => ['nullable', 'string', 'max:2000'],
            'observations' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
