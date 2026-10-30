<?php

namespace App\Http\Requests\DesignOfficeWorkflow;

use App\Enums\AuditOccurrencePhotoRole;
use App\Models\AuditOccurrence;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A photo of one of the occurrence's boxes linked in a role (before, after, other).
 */
class LinkOccurrencePhotoRequest extends FormRequest
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
            'photo_id' => ['required', 'integer', 'min:1'],
            'role' => ['required', Rule::enum(AuditOccurrencePhotoRole::class)],
        ];
    }
}
