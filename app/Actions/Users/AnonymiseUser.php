<?php

namespace App\Actions\Users;

use App\Enums\SessionEndReason;
use App\Logs\SecurityLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Support\UserSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Anonymises a person who left (RGPD-04/07): the account keeps its id, so the work history (tasks, audits, billing
 * entries, equipment movements, logs) stays linked to it, but nothing identifies the person any more. The name
 * becomes « Former employee #id » (in the installation's language) everywhere a copy of it was kept as text, the
 * contact details, the vehicle and the personal documents are erased, the positions of the vehicle photos are cleared
 * (the photos stay as the vehicle's history) and the account can no longer sign in.
 * Expense reports keep the name printed on them (accounting documents kept for the legal period).
 */
class AnonymiseUser
{
    /**
     * Copies of a person's name kept as text next to the reference to them: table => [name column => user column].
     *
     * @var array<string, array<string, string>>
     */
    public const array NAME_COPIES = [
        'audits' => ['finished_by_name' => 'finished_by', 'validated_by_name' => 'validated_by', 'deleted_by_name' => 'deleted_by'],
        'audit_boxes' => ['status_by_name' => 'status_by', 'confirmed_by_name' => 'confirmed_by', 'report_by_name' => 'report_by', 'returned_by_name' => 'returned_by'],
        'audit_box_rechecks' => ['saved_by_name' => 'saved_by'],
        'audit_drawer_rechecks' => ['saved_by_name' => 'saved_by'],
        'audit_control_points' => ['report_by_name' => 'report_by'],
        'audit_photos' => ['user_name' => 'user_id'],
        'audit_folder_validations' => ['user_name' => 'user_id'],
        'audit_remarks' => ['user_name' => 'user_id', 'acknowledged_by_name' => 'acknowledged_by'],
        'audit_field_changes' => ['user_name' => 'user_id'],
        'client_remarks' => ['acknowledged_by_name' => 'acknowledged_by'],
        'client_remark_replies' => ['author_name' => 'user_id'],
        'inventory_attestations' => ['created_by_name' => 'created_by'],
        'inventory_movements' => ['user_name' => 'user_id', 'from_name' => 'from_user_id', 'to_name' => 'to_user_id', 'holder_name' => 'holder_id'],
        'media_files' => ['uploader_name' => 'uploaded_by'],
        'expense_drafts' => ['name' => 'user_id'],
    ];

    /** JSON columns of equipment items keeping who asked or declared (`by`) with a copy of their name (`by_name`). */
    private const array JSON_NAME_COPIES = ['anomaly', 'pending_request'];

    /** Identifying fields of the person recorded in the « Logs » journal, which a restore could otherwise bring back. */
    private const array JOURNAL_FIELDS = ['name', 'username', 'email', 'phone', 'locale'];

    public function __construct(private DeletePersonalDocuments $documents, private UserSessions $sessions, private SecurityLog $log) {}

    public function handle(User $user): void
    {
        if ($user->anonymised_at !== null) {
            return;
        }

        $name = __('users.anonymised_name', ['number' => $user->id], (string) config('app.locale'));
        $username = $this->username($user);

        activity()->withoutLogging(function () use ($user, $name, $username): void {
            DB::transaction(function () use ($user, $name, $username): void {
                $user->forceFill([
                    'name' => $name, 'username' => $username, 'email' => null, 'phone' => null, 'locale' => null,
                    'password' => null, 'legacy_password_hash' => null, 'legacy_password_expires_at' => null, 'must_change_password' => false,
                    'anonymised_at' => now(),
                ])->save();

                foreach (self::NAME_COPIES as $table => $columns) {
                    foreach ($columns as $nameColumn => $userColumn) {
                        DB::table($table)->where($userColumn, $user->id)->update([$nameColumn => $name]);
                    }
                }

                foreach (self::JSON_NAME_COPIES as $column) {
                    DB::table('inventory_items')->where($column.'->by', $user->id)->update([$column.'->by_name' => $name]);
                }

                Vehicle::query()->withTrashed()->where('user_id', $user->id)->forceDelete();
                VehiclePhoto::query()->where('user_id', $user->id)->update(['latitude' => null, 'longitude' => null]);
                $this->documents->handle($user);
                $this->scrubJournal($user, $name, $username);
            });

            $this->sessions->revokeAll($user, null, SessionEndReason::UserDisabled);
        });

        $this->log->info(SecurityLog::USER_ANONYMISED, ['user_id' => $user->id]);
    }

    /**
     * A username nobody else has (usernames are unique and used to sign in).
     */
    private function username(User $user): string
    {
        $username = 'former-'.$user->id;

        return User::query()->withTrashed()->where('username', $username)->whereKeyNot($user->id)->exists()
            ? $username.'-'.Str::lower(Str::random(6))
            : $username;
    }

    /**
     * Replaces the person's identifying fields in the recorded versions of their account.
     */
    private function scrubJournal(User $user, string $name, string $username): void
    {
        $replacements = ['name' => $name, 'username' => $username];

        Activity::query()->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->each(function (Activity $activity) use ($replacements): void {
            $changes = $activity->attribute_changes?->all() ?? [];

            foreach (['attributes', 'old'] as $version) {
                foreach (self::JOURNAL_FIELDS as $field) {
                    if (is_array($changes[$version] ?? null) && array_key_exists($field, $changes[$version])) {
                        $changes[$version][$field] = $replacements[$field] ?? null;
                    }
                }
            }

            $activity->attribute_changes = collect($changes);
            $activity->saveQuietly();
        });
    }
}
