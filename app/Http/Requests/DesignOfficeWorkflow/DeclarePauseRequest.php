<?php

namespace App\Http\Requests\DesignOfficeWorkflow;

use App\Models\AuditBox;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A pause declared by a technician on the box they work on, with an optional reason.
 */
class DeclarePauseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $box = $this->route('box');

        return $box instanceof AuditBox && $this->user()?->can('declarePause', $box) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
