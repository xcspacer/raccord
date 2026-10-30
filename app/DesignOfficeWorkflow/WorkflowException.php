<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditFolder;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * An action of the design office workflow that was refused. The reason is a stable key (« not_responsible »,
 * « already_decided »…) the phone and the screens understand, translated under design_office_workflow.errors; the
 * status follows HTTP: 403 not allowed, 404 unknown, 409 conflict with the current state, 422 invalid. The context
 * carries what helps the person (the blocking box, the first decision, the folders missing…).
 */
class WorkflowException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(public readonly string $reason, public readonly int $status = 422, public readonly array $context = [])
    {
        parent::__construct($reason);
    }

    /**
     * The message in the current language, with the context values that fit a sentence.
     */
    public function translated(): string
    {
        $replace = [];

        foreach ($this->context as $key => $value) {
            $replace[$key] = match (true) {
                $key === 'folders' && is_array($value) => implode(', ', array_map(fn (mixed $folder) => AuditFolder::tryFrom((string) $folder)?->label() ?? (string) $folder, $value)),
                $key === 'at' && is_string($value) => CarbonImmutable::parse($value)->timezone((string) config('platform.timezone'))->format('d/m/Y H:i:s'),
                is_array($value) => implode(', ', array_map(fn (mixed $item) => is_scalar($item) ? (string) $item : '', $value)),
                is_scalar($value) => (string) $value,
                default => '',
            };
        }

        return __('design_office_workflow.errors.'.$this->reason, $replace);
    }

    /**
     * @return array{reason: string, message: string, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['reason' => $this->reason, 'message' => $this->translated(), 'context' => $this->context];
    }
}
