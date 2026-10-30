<?php

namespace App\Actions\Users;

use App\Logs\SecurityLog;
use App\Models\Absence;
use App\Models\BillingExpense;
use App\Models\DayPunch;
use App\Models\ExpenseLine;
use App\Models\ExpenseReport;
use App\Models\InventoryItem;
use App\Models\User;
use App\Models\UserDocument;
use App\Models\Vehicle;
use App\Support\ZipDownload;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\Response;
use ZipStream\ZipStream;

/**
 * Right of access and portability (RGPD-06): a ZIP with one JSON file per kind of personal data of a person, and
 * the files of their personal documents. Streamed without a temporary file and written to the security log.
 */
class ExportPersonalData
{
    public function __construct(private ZipDownload $zip, private SecurityLog $log) {}

    public function download(User $user): Response
    {
        $fileName = __('users.export_data_file', ['username' => Str::slug($user->username), 'date' => now(config('platform.timezone'))->format('Y-m-d')]).'.zip';

        $this->log->info(SecurityLog::PERSONAL_DATA_EXPORTED, ['user_id' => $user->id]);

        return $this->zip->stream('personal_data', $fileName, function (ZipStream $zip) use ($user): void {
            foreach ($this->sections($user) as $name => $data) {
                $zip->addFile($name.'.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            UserDocument::query()->where('user_id', $user->id)->with('file')->each(function (UserDocument $document) use ($zip): void {
                if ($document->file !== null) {
                    ZipDownload::addStoredFile($zip, $this->documentPath($document), $document->file);
                }
            });
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function sections(User $user): array
    {
        $user->loadMissing(['roles', 'agency', 'company']);

        return [
            'profile' => [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->getRoleNames()->first(),
                'agency' => $user->all_agencies ? 'all' : $user->agency?->name,
                'company' => $user->company?->name,
                'locale' => $user->locale,
                'departure_date' => $user->departure_date?->toDateString(),
                'created_at' => $this->time($user->created_at),
            ],
            'day_punches' => DayPunch::query()->where('user_id', $user->id)->orderBy('date')->get()->map(fn (DayPunch $punch) => [
                'date' => $punch->date->toDateString(),
                'start' => ['at' => $this->time($punch->start_at), 'latitude' => $punch->start_latitude, 'longitude' => $punch->start_longitude, 'accuracy_m' => $punch->start_accuracy, 'position_missing' => $punch->start_geo_missing, 'offline' => $punch->start_offline],
                'end' => ['at' => $this->time($punch->end_at), 'latitude' => $punch->end_latitude, 'longitude' => $punch->end_longitude, 'accuracy_m' => $punch->end_accuracy, 'position_missing' => $punch->end_geo_missing, 'offline' => $punch->end_offline, 'declared' => $punch->end_declared],
                'positions_erased_at' => $this->time($punch->location_purged_at),
            ])->all(),
            'absences' => Absence::query()->where('user_id', $user->id)->orderBy('starts_on')->get()->map(fn (Absence $absence) => [
                'type' => $absence->type->value,
                'starts_on' => $absence->starts_on->toDateString(),
                'ends_on' => $absence->ends_on->toDateString(),
                'note' => $absence->note,
            ])->all(),
            'expense_reports' => ExpenseReport::query()->where('user_id', $user->id)->orderBy('created_at')->get()->map(fn (ExpenseReport $report) => [
                'month' => $report->month,
                'name' => $report->name,
                'total_cents' => $report->total_cents,
                'lines' => $report->lines_count,
                'created_at' => $this->time($report->created_at),
            ])->all(),
            'expense_lines_in_progress' => ExpenseLine::query()->where('user_id', $user->id)->orderBy('id')->get()->map(fn (ExpenseLine $line) => [
                'spent_on' => $line->spent_on?->toDateString(),
                'nature' => $line->nature->value,
                'amount_cents' => $line->amount_cents,
                'merchant' => $line->merchant,
                'comment' => $line->comment,
                'status' => $line->status->value,
            ])->all(),
            'billing_expenses' => BillingExpense::query()->where('user_id', $user->id)->orderBy('spent_on')->get()->map(fn (BillingExpense $expense) => [
                'spent_on' => $expense->spent_on->toDateString(),
                'category' => $expense->category->value,
                'category_other' => $expense->category_other,
                'amount' => $expense->amount,
                'mileage_km' => $expense->mileage_km,
                'comment' => $expense->comment,
                'status' => $expense->status->value,
                'rejection_reason' => $expense->rejection_reason,
            ])->all(),
            'personal_documents' => UserDocument::query()->where('user_id', $user->id)->with('file')->orderBy('id')->get()->map(fn (UserDocument $document) => [
                'type' => $document->type->value,
                'original_name' => $document->original_name,
                'expires_on' => $document->expires_on?->toDateString(),
                'uploaded_at' => $this->time($document->created_at),
                'file' => $this->documentPath($document),
            ])->all(),
            'equipment' => InventoryItem::query()->where('holder_id', $user->id)->orderBy('id')->get()->map(fn (InventoryItem $item) => [
                'label' => $item->label,
                'category' => $item->category,
                'brand' => $item->brand,
                'model' => $item->model,
                'serial' => $item->serial,
                'quantity' => $item->quantity,
                'condition' => $item->condition->value,
                'status' => $item->status->value,
                'assigned_on' => $item->assigned_on?->toDateString(),
            ])->all(),
            'vehicle' => Vehicle::query()->where('user_id', $user->id)->orderBy('id')->get()->map(fn (Vehicle $vehicle) => [
                'model' => $vehicle->model,
                'plate' => $vehicle->plate,
            ])->all(),
            'change_history' => $this->changeHistory($user),
        ];
    }

    /**
     * Entries of the journals caused by the person (« Logs » and the other change journals), oldest first.
     *
     * @return list<array{at: string|null, log: string|null, event: string|null, description: string, subject_type: string|null, subject_id: int|null, changes: array<string, mixed>, details: array<string, mixed>}>
     */
    private function changeHistory(User $user): array
    {
        return Activity::query()
            ->where('causer_type', $user->getMorphClass())
            ->where('causer_id', $user->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Activity $activity) => [
                'at' => $this->time($activity->created_at),
                'log' => $activity->log_name,
                'event' => $activity->event,
                'description' => $activity->description,
                'subject_type' => $activity->subject_type === null ? null : class_basename($activity->subject_type),
                'subject_id' => $activity->subject_id,
                'changes' => $activity->attribute_changes?->toArray() ?? [],
                'details' => Arr::except($activity->properties?->toArray() ?? [], ['batch']),
            ])->values()->all();
    }

    private function documentPath(UserDocument $document): string
    {
        return 'documents/'.$document->type->value.'-'.$document->id.'.'.$document->file->extension;
    }

    private function time(?CarbonInterface $at): ?string
    {
        return $at?->copy()->timezone(config('platform.timezone'))->toIso8601String();
    }
}
