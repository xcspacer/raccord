<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditAiDraftStatus;
use App\Models\AuditAiDraft;
use App\Models\AuditOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What a person does with a text drafted by the AI: correct it, approve it (it becomes the occurrence's publishable
 * text, the latest approved one wins) or reject it. Only a draft changes; approved and rejected texts stay as they
 * were, as the internal history. Every step is journaled.
 */
class AiDrafts
{
    public const int MIN_TEXT = 10;

    public const int MAX_TEXT = 6000;

    public function __construct(private WorkflowJournal $journal) {}

    /**
     * @throws WorkflowException
     */
    public function edit(User $actor, AuditAiDraft $draft, string $text): AuditAiDraft
    {
        $text = trim($text);

        if (mb_strlen($text) < self::MIN_TEXT) {
            throw new WorkflowException('ai_text_too_short', 422);
        }

        return $this->change($actor, $draft, 'ai_draft_edited', ['text' => mb_substr($text, 0, self::MAX_TEXT), 'edited_by' => $actor->id]);
    }

    /**
     * @throws WorkflowException
     */
    public function approve(User $actor, AuditAiDraft $draft): AuditAiDraft
    {
        return $this->change($actor, $draft, 'ai_draft_approved', ['status' => AuditAiDraftStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now()]);
    }

    /**
     * @throws WorkflowException
     */
    public function reject(User $actor, AuditAiDraft $draft): AuditAiDraft
    {
        return $this->change($actor, $draft, 'ai_draft_rejected', ['status' => AuditAiDraftStatus::Rejected]);
    }

    /**
     * The text that may be published for the occurrence: its latest approved draft, if any.
     */
    public function published(AuditOccurrence $occurrence): ?AuditAiDraft
    {
        return $occurrence->aiDrafts()->where('status', AuditAiDraftStatus::Approved)->latest('approved_at')->latest('id')->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws WorkflowException
     */
    private function change(User $actor, AuditAiDraft $draft, string $event, array $attributes): AuditAiDraft
    {
        return DB::transaction(function () use ($actor, $draft, $event, $attributes): AuditAiDraft {
            $locked = AuditAiDraft::query()->with('occurrence')->lockForUpdate()->findOrFail($draft->id);

            if ($locked->status !== AuditAiDraftStatus::Draft) {
                throw new WorkflowException('ai_draft_closed', 409);
            }

            $locked->forceFill($attributes)->save();
            $this->journal->record($event, $locked, $actor, ['occurrence' => $locked->occurrence->reference]);

            return $locked;
        });
    }
}
