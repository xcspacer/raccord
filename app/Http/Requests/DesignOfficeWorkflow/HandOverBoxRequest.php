<?php

namespace App\Http\Requests\DesignOfficeWorkflow;

use App\Models\AuditBox;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * « Passer la main »: the participant chosen by the responsible technician, or none (the first participant, else the
 * supervisors are asked to name one).
 */
class HandOverBoxRequest extends FormRequest
{
    public function authorize(): bool
    {
        $box = $this->route('box');

        return $box instanceof AuditBox && $this->user()?->can('handOver', $box) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'to_user_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
