<?php

namespace App\Http\Requests\DesignOfficeWorkflow;

use App\Models\AuditBox;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Declaration of an occurrence on a box from the phone: the anomaly type, a description, the « before » photos
 * of the box (ids of photos already received) and, optionally, where it is and the operation id of the phone (a
 * resent declaration answers the first one).
 */
class StoreOccurrenceRequest extends FormRequest
{
    /**
     * Refused as the policy says (an audit that is not the person's does not exist for them).
     */
    public function authorize(): Response
    {
        $subject = $this->route('box');

        return $subject instanceof AuditBox ? Gate::forUser($this->user())->inspect('createOccurrence', $subject) : Response::denyAsNotFound();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operation_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'anomaly_type_id' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location_note' => ['nullable', 'string', 'max:500'],
            'before_photo_ids' => ['sometimes', 'array', 'max:50'],
            'before_photo_ids.*' => ['integer', 'min:1'],
        ];
    }
}
