<?php

namespace App\Http\Requests\DesignOfficeWorkflow;

use App\Models\AuditBox;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A corrected position of a box proposed from the phone (the point moved on the map).
 */
class ProposePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $box = $this->route('box');
        abort_unless($box instanceof AuditBox, 404);
        Gate::forUser($this->user())->authorize('proposePosition', $box);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
