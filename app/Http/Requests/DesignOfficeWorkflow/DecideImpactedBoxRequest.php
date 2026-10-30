<?php

namespace App\Http\Requests\DesignOfficeWorkflow;

use App\Models\AuditOccurrence;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The design office confirms a proposed impacted box, or excludes it with a reason.
 */
class DecideImpactedBoxRequest extends FormRequest
{
    /**
     * Refused as the policy says (an audit that is not the person's does not exist for them).
     */
    public function authorize(): Response
    {
        $subject = $this->route('occurrence');

        return $subject instanceof AuditOccurrence ? Gate::forUser($this->user())->inspect('decideImpactedBox', $subject) : Response::denyAsNotFound();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
