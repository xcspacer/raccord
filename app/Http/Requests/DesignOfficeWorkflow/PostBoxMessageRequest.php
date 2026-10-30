<?php

namespace App\Http\Requests\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\BoxMessages;
use App\Enums\AuditBoxMessageKind;
use App\Models\AuditBox;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A message in the internal chat of a box: the operation id of the phone (a resent message is a repeat), the text, its
 * kind (chat for everyone; observation or additional control for the design office and the supervisors) and the photo
 * it is about.
 */
class PostBoxMessageRequest extends FormRequest
{
    public function authorize(): Response
    {
        $box = $this->route('box');

        return $box instanceof AuditBox ? Gate::forUser($this->user())->inspect('postMessage', $box) : Response::denyAsNotFound();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operation_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'body' => ['required', 'string', 'max:'.BoxMessages::MAX_BODY],
            'kind' => ['sometimes', Rule::enum(AuditBoxMessageKind::class)],
            'photo_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function kind(): AuditBoxMessageKind
    {
        return AuditBoxMessageKind::tryFrom((string) $this->validated('kind', AuditBoxMessageKind::Chat->value)) ?? AuditBoxMessageKind::Chat;
    }
}
