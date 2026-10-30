<?php

namespace App\Http\Requests\DesignOfficeWorkflow;

use App\Models\AuditOccurrence;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * An optical anomaly put on hold: the written justification of why it cannot be corrected now.
 */
class HoldOccurrenceRequest extends FormRequest
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
            'justification' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
