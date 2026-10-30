<?php

namespace App\Http\Requests\DesignOfficeWorkflow;

use App\Enums\AuditConformity;
use App\Models\AuditOccurrence;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Result of an occurrence on one of its confirmed boxes: conforming or not, with an optional note.
 */
class ImpactedBoxResultRequest extends FormRequest
{
    /**
     * Refused as the policy says (an audit that is not the person's does not exist for them).
     */
    public function authorize(): Response
    {
        $subject = $this->route('occurrence');

        return $subject instanceof AuditOccurrence ? Gate::forUser($this->user())->inspect('setImpactedResult', $subject) : Response::denyAsNotFound();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'conformity' => ['required', Rule::in([AuditConformity::Conforming->value, AuditConformity::NonConforming->value])],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
