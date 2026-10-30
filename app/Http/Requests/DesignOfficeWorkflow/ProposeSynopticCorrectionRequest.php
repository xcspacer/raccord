<?php

namespace App\Http\Requests\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\SynopticCorrections;
use App\Models\Audit;
use App\Models\AuditSynopticCorrection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A correction of the synoptic proposed from the phone: its text, the box it is about (name, optional) and a photo of
 * the PM already sent (optional), with the operation id of the phone.
 */
class ProposeSynopticCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $audit = $this->route('audit');
        abort_unless($audit instanceof Audit, 404);
        Gate::forUser($this->user())->authorize('create', [AuditSynopticCorrection::class, $audit]);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operation_id' => ['required', 'string', 'max:64'],
            'text' => ['required', 'string', 'max:'.SynopticCorrections::MAX_BODY],
            'box' => ['nullable', 'string', 'max:60'],
            'photo' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
