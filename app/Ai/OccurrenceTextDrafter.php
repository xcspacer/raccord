<?php

namespace App\Ai;

use App\DesignOfficeWorkflow\Dossier\DossierLanguage;
use App\DesignOfficeWorkflow\WorkflowJournal;
use App\Enums\AiFeature;
use App\Enums\AuditAiDraftStatus;
use App\Models\AuditAiDraft;
use App\Models\AuditOccurrence;
use App\Models\AuditPhoto;
use App\Models\User;
use App\Settings\DesignOfficeWorkflowSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Drafts the text of an occurrence for the design office, only when a person asks for it (never automatically), from
 * the photos linked to that occurrence and nothing else of the audit: its type and description go with them, never a
 * name, an address or a position. The text is written in the language of the client the audit is delivered to and is
 * always a draft: a person edits it, approves or rejects it, and only an approved text may be published. Each
 * generation is kept (internal history) and journaled. Switched off until the provider's retention terms are approved
 * (setting « AI drafts »), and off without an API key.
 */
class OccurrenceTextDrafter
{
    /** Photos sent at most per request (the first ones linked to the occurrence). */
    public const int MAX_PHOTOS = 8;

    private const int MAX_PHOTO_BYTES = 6 * 1024 * 1024;

    /** @var list<string> Parts of the answer, in the order they are written. */
    public const array SECTIONS = ['facts', 'observations', 'hypotheses', 'missing'];

    public function __construct(
        private AnthropicClient $client,
        private AiQuota $quota,
        private DesignOfficeWorkflowSettings $settings,
        private DossierLanguage $language,
        private WorkflowJournal $journal,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settings->ai_drafts_enabled && $this->client->isConfigured();
    }

    /**
     * Exactly what would be sent: the photos linked to the occurrence, in the order they were linked.
     *
     * @return Collection<int, AuditPhoto>
     */
    public function scope(AuditOccurrence $occurrence): Collection
    {
        $ids = $occurrence->photoLinks()->orderBy('id')->pluck('audit_photo_id')->unique()->take(self::MAX_PHOTOS)->values()->all();

        return AuditPhoto::query()->with('file')->whereIn('id', $ids)->get()->sortBy(fn (AuditPhoto $photo) => array_search($photo->id, $ids, true))->values();
    }

    public function locale(AuditOccurrence $occurrence): string
    {
        return $this->language->for($occurrence->audit);
    }

    /**
     * @throws AiException disabled, not_configured, quota_reached, unavailable, unreadable_answer, invalid_image
     */
    public function draft(User $user, AuditOccurrence $occurrence): AuditAiDraft
    {
        if (! $this->settings->ai_drafts_enabled) {
            throw new AiException('disabled');
        }

        if (! $this->client->isConfigured()) {
            throw new AiException('not_configured');
        }

        $occurrence->loadMissing(['audit', 'type']);
        $photos = $this->scope($occurrence);

        if ($photos->isEmpty()) {
            throw new AiException('invalid_image');
        }

        $images = $photos->map(function (AuditPhoto $photo): array {
            $contents = Storage::disk($photo->file->disk)->get($photo->file->path);

            if ($contents === null || strlen($contents) > self::MAX_PHOTO_BYTES) {
                throw new AiException('invalid_image');
            }

            return ['data' => base64_encode($contents), 'media_type' => 'image/jpeg'];
        })->all();

        $this->quota->consume($user, AiFeature::DraftOccurrenceText);
        $locale = $this->locale($occurrence);
        $answer = $this->client->askForJson($this->prompt($occurrence, $locale, count($images)), $images, 1200);

        $draft = AuditAiDraft::query()->create([
            'audit_occurrence_id' => $occurrence->id,
            'requested_by' => $user->id,
            'photo_ids' => $photos->modelKeys(),
            'locale' => $locale,
            'model' => (string) config('services.anthropic.model'),
            'text' => $this->compose($answer, $locale),
            'status' => AuditAiDraftStatus::Draft,
        ]);

        $this->journal->record('ai_draft_generated', $draft, $user, ['occurrence' => $occurrence->reference, 'photos' => $photos->modelKeys(), 'locale' => $locale, 'model' => $draft->model]);

        return $draft;
    }

    /**
     * The answer as a text with one heading per part, in the client's language; an empty part says so.
     *
     * @param  array<string, mixed>  $answer
     */
    private function compose(array $answer, string $locale): string
    {
        $parts = [];

        foreach (self::SECTIONS as $section) {
            $lines = array_values(array_filter(array_map(fn (mixed $line) => is_scalar($line) ? trim((string) $line) : '', (array) ($answer[$section] ?? [])), fn (string $line) => $line !== ''));
            $body = $lines === [] ? '—' : implode("\n", array_map(fn (string $line) => '- '.mb_substr($line, 0, 500), array_slice($lines, 0, 12)));
            $parts[] = __('design_office_workflow.ai.sections.'.$section, [], $locale)."\n".$body;
        }

        return implode("\n\n", $parts);
    }

    private function prompt(AuditOccurrence $occurrence, string $locale, int $count): string
    {
        $language = __('platform.languages.'.$locale, [], 'en_GB');
        $type = $occurrence->type->displayLabel();
        $nature = $occurrence->nature->value;
        $description = mb_substr($occurrence->description, 0, 1500);

        return <<<PROMPT
You help the design office of a fibre-optic (FTTH) contractor describe a non-conformity found on a fibre box, for the official delivery dossier of its client.
You receive {$count} photo(s) of this one occurrence, and nothing else of the site.
Anomaly type: {$type} ({$nature}).
Description written on site: {$description}
Write in {$language}. Answer ONLY with a JSON object, no text around it, of this shape:
{"facts":["..."],"observations":["..."],"hypotheses":["..."],"missing":["..."]}
Rules:
- facts: only what the type, the description and the photos establish without doubt.
- observations: what can be seen on the photos (state, position, labels), each one short.
- hypotheses: possible causes or consequences, clearly worded as hypotheses.
- missing: what the photos do not allow to tell (information insufficient).
- Never invent a measurement, a distance, a power level, a date, a name or a reference that is not visible or given.
- Never mention people. Short plain sentences.
PROMPT;
    }
}
