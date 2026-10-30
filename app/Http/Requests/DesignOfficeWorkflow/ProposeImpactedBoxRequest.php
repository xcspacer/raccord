<?php

namespace App\Http\Requests\DesignOfficeWorkflow;

use App\Models\AuditOccurrence;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Another box of the audit impacted by an optical anomaly, with the technical justification.
 */
class ProposeImpactedBoxRequest extends FormRequest
{
    /**
     * Refused as the policy says (an audit that is not the person's does not exist for them).
     */
    public function authorize(): Response
    {
        $subject = $this->route('occurrence');

        return $subject instanceof AuditOccurrence ? Gate::forUser($this->user())->inspect('proposeImpactedBox', $subject) : Response::denyAsNotFound();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'box_id' => ['required', 'integer', 'min:1'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
