<?php

namespace App\Billing;

use App\Actions\Files\StoreUploadedFile;
use App\Enums\BillingClosureStatus;
use App\Enums\BillingEntryType;
use App\Enums\BillingFormType;
use App\Enums\BillingPhotoCategory;
use App\Enums\BillingSchedule;
use App\Models\BillingClient;
use App\Models\BillingEntry;
use App\Models\BillingEntryPhoto;
use App\Models\BillingInterventionType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates or updates an entry from the form: only the fields of the client's form are kept, partners and article
 * lines are replaced, new photos are stored and removed ones deleted, all when the entry is saved.
 *
 * @phpstan-import-type EntryInput from EntryRules
 */
class SaveBillingEntry
{
    public function __construct(private EntryRules $rules, private StoreUploadedFile $files, private BillingJournal $journal) {}

    /**
     * @param  EntryInput  $input
     * @param  array<string, list<UploadedFile>>  $uploads  new photos by category (« none », « before », « after »)
     * @param  list<int>  $removedPhotoIds
     */
    public function handle(User $owner, BillingClient $client, array $input, ?BillingEntry $entry, array $uploads = [], array $removedPhotoIds = [], ?User $by = null): BillingEntry
    {
        $works = $input['type'] === BillingEntryType::Works->value;
        $type = $input['typeId'] ? BillingInterventionType::query()->where('billing_client_id', $client->id)->find($input['typeId']) : null;
        $form = $client->form_type;
        $usesType = ($works && $form === BillingFormType::DayRatePair) || (! $works && in_array($form, [BillingFormType::TypeRate, BillingFormType::LevelSchedule], true));
        $grid = ! $works && $form === BillingFormType::LevelSchedule && $type?->is_billable;
        $interval = $this->rules->isTimed($client, $works, $type) ? $this->rules->interval($input) : null;
        $partners = $input['alone'] ? [] : array_values(array_unique(array_map('intval', $input['partners'])));

        return DB::transaction(function () use ($owner, $client, $input, $entry, $uploads, $removedPhotoIds, $by, $works, $type, $form, $usesType, $grid, $interval, $partners): BillingEntry {
            $attributes = [
                'type' => $works ? BillingEntryType::Works : BillingEntryType::Breakdown,
                'billing_client_id' => $client->id,
                'work_date' => $input['date'],
                'ticket' => trim($input['ticket']),
                'is_alone' => $input['alone'],
                'billing_intervention_type_id' => $usesType ? $type?->id : null,
                'level' => $grid ? (int) $input['level'] : null,
                'schedule' => $grid ? BillingSchedule::from((string) $input['schedule']) : null,
                'started_at' => $interval[0] ?? null,
                'ended_at' => $interval[1] ?? null,
                'billing_operator_id' => ! $works && $form === BillingFormType::Hourly ? (int) $input['operatorId'] : null,
                'closure_status' => ! $works && $form === BillingFormType::TicketFlat ? BillingClosureStatus::tryFrom((string) ($input['closure'] ?? '')) ?? BillingClosureStatus::Frozen : null,
                'comment' => trim($input['comment']),
            ];

            if ($entry === null) {
                $entry = BillingEntry::query()->create($attributes + ['uuid' => (string) Str::uuid(), 'user_id' => $owner->id]);
            } else {
                $entry->update($attributes);
            }

            $entry->partners()->sync(array_values(array_diff($partners, [$entry->user_id])));
            $entry->lines()->delete();

            if ($works && $form !== BillingFormType::DayRatePair) {
                foreach ($this->mergedLines($input['lines']) as $article => $quantity) {
                    $entry->lines()->create(['billing_article_id' => $article, 'quantity' => $quantity]);
                }
            }

            foreach ($entry->photos()->with('file')->whereIn('id', $removedPhotoIds)->get() as $photo) {
                /** @var BillingEntryPhoto $photo */
                $stored = $photo->file;
                $photo->delete();
                $this->files->release($stored);
            }

            foreach ($uploads as $category => $files) {
                foreach ($files as $file) {
                    $entry->photos()->create([
                        'category' => $works ? BillingPhotoCategory::tryFrom($category) : null,
                        'stored_file_id' => $this->files->handleImage($file)->id,
                        'uploaded_by' => ($by ?? $owner)->id,
                    ]);
                }
            }

            $this->journal->record(BillingJournal::ENTRY_SAVED, $entry, $by ?? $owner, $this->journal->entry($entry));

            return $entry->refresh();
        });
    }

    /**
     * The same article added twice adds up its quantities.
     *
     * @param  list<array{article: int|string, quantity: float|int|string}>  $lines
     * @return array<int, float>
     */
    private function mergedLines(array $lines): array
    {
        $merged = [];

        foreach ($lines as $line) {
            $merged[(int) $line['article']] = ($merged[(int) $line['article']] ?? 0) + (float) $line['quantity'];
        }

        return $merged;
    }
}
