<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditBoxMessageKind;
use App\Models\AuditBox;
use App\Models\AuditBoxMessage;
use App\Models\AuditPhoto;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Internal messages of a box between its team, the design office and the supervisors, never shown to a client. Anyone
 * of the workflow writes in the chat (a phone may have prepared the message offline: its operation id makes a resent
 * message answer the first one). The design office and the supervisors also write early observations on photos already
 * received and requests for an additional control: those are blocking (the box cannot be submitted) and are resolved
 * by the design office or a supervisor only; an answer of the technician never resolves them.
 */
class BoxMessages
{
    /** Longest message body. */
    public const int MAX_BODY = 2000;

    public function __construct(private WorkflowGuard $guard, private WorkflowJournal $journal) {}

    /**
     * @return array{message: AuditBoxMessage, duplicate: bool}
     *
     * @throws WorkflowException
     */
    public function post(User $author, AuditBox $box, string $operationId, string $body, AuditBoxMessageKind $kind = AuditBoxMessageKind::Chat, ?int $photoId = null): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $operationId) !== 1) {
            throw new WorkflowException('operation_id_required');
        }

        $repeat = $this->repeat($author, $box, $operationId);

        if ($repeat !== null) {
            return ['message' => $repeat, 'duplicate' => true];
        }

        $body = trim($body);

        if ($body === '' || mb_strlen($body) > self::MAX_BODY) {
            throw new WorkflowException('message_required');
        }

        if ($kind !== AuditBoxMessageKind::Chat && ! WorkflowRoles::isSupervisor($author) && ! WorkflowRoles::isReviewer($author)) {
            throw new WorkflowException('forbidden', 403);
        }

        try {
            return DB::transaction(function () use ($author, $box, $operationId, $body, $kind, $photoId): array {
                $box = AuditBox::query()->with('audit')->lockForUpdate()->findOrFail($box->id);
                $this->guard->ensureOpen($box->audit);
                $this->guard->ensureBoxInService($box);

                if ($photoId !== null && ! AuditPhoto::query()->whereKey($photoId)->where('audit_box_id', $box->id)->exists()) {
                    throw new WorkflowException('photo_not_in_box');
                }

                $message = AuditBoxMessage::query()->create([
                    'audit_box_id' => $box->id, 'author_id' => $author->id, 'kind' => $kind, 'body' => $body,
                    'is_blocking' => $kind !== AuditBoxMessageKind::Chat, 'audit_photo_id' => $photoId, 'client_operation_id' => $operationId,
                ]);
                $this->journal->record('message_posted', $message, $author, [
                    'box' => $box->name, 'kind' => $kind->value, 'blocking' => $message->is_blocking, 'photo_id' => $photoId, 'correlation_id' => $operationId,
                ]);

                return ['message' => $message, 'duplicate' => false];
            });
        } catch (UniqueConstraintViolationException) {
            return ['message' => $this->repeat($author, $box, $operationId) ?? throw new WorkflowException('operation_id_reused', 409), 'duplicate' => true];
        }
    }

    /**
     * @throws WorkflowException
     */
    public function resolve(User $actor, AuditBoxMessage $message): void
    {
        DB::transaction(function () use ($actor, $message): void {
            $message = AuditBoxMessage::query()->with('box.audit')->lockForUpdate()->findOrFail($message->id);
            $this->guard->ensureDesignOffice($message->box->audit);

            if (! WorkflowRoles::isSupervisor($actor) && ! WorkflowRoles::isReviewer($actor)) {
                throw new WorkflowException('forbidden', 403);
            }

            if (! $message->is_blocking) {
                throw new WorkflowException('not_blocking', 409);
            }

            if ($message->isResolved()) {
                return;
            }

            $message->forceFill(['resolved_by' => $actor->id, 'resolved_at' => now()])->save();
            $this->journal->record('message_resolved', $message, $actor, ['box' => $message->box->name, 'kind' => $message->kind->value]);
        });
    }

    /**
     * The messages of a box as the people of the workflow read them, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function thread(AuditBox $box, User $viewer): array
    {
        return AuditBoxMessage::query()->where('audit_box_id', $box->id)->with(['author.roles', 'resolver'])->orderBy('id')->get()
            ->map(fn (AuditBoxMessage $message) => [
                'id' => $message->id,
                'kind' => $message->kind->value,
                'kind_label' => $message->kind->label(),
                'body' => $message->body,
                'is_blocking' => $message->is_blocking,
                'photo_id' => $message->audit_photo_id,
                'by' => $message->author->name,
                'side' => WorkflowRoles::isTechnician($message->author) ? 'team' : 'office',
                'is_mine' => $message->author_id === $viewer->id,
                'at' => $message->created_at?->toIso8601String(),
                'resolved' => $message->resolved_at === null ? null : ['by' => $message->resolver?->name, 'at' => $message->resolved_at->toIso8601String()],
            ])->values()->all();
    }

    /**
     * The message already recorded under this operation id of the person, when it is about the same box.
     *
     * @throws WorkflowException
     */
    private function repeat(User $author, AuditBox $box, string $operationId): ?AuditBoxMessage
    {
        $repeat = AuditBoxMessage::query()->where('author_id', $author->id)->where('client_operation_id', $operationId)->first();

        if ($repeat !== null && $repeat->audit_box_id !== $box->id) {
            throw new WorkflowException('operation_id_reused', 409);
        }

        return $repeat;
    }
}
