<?php

namespace App\Http\Requests\DesignOfficeWorkflow;

use App\Models\AuditBox;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Submission of the repairs of a box by the responsible of its intervention: the operation id of the phone (a resent
 * submission is a repeat) and whether the saved recheck of the box is attached. A box of an audit the person does
 * not work on does not exist for them (404), as on the other box actions.
 */
class SubmitRepairsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $box = $this->route('box');
        abort_unless($box instanceof AuditBox, 404);
        Gate::authorize('submitRepairs', $box);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operation_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'attach_recheck' => ['sometimes', 'boolean'],
        ];
    }
}
