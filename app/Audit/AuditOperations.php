<?php

namespace App\Audit;

use App\DesignOfficeWorkflow\BoxPositions;
use App\DesignOfficeWorkflow\FieldDataGuard;
use App\DesignOfficeWorkflow\LegacyBoxStatus;
use App\Enums\AuditBoxKind;
use App\Enums\AuditBoxSource;
use App\Enums\AuditBoxStatus;
use App\Enums\AuditFibreType;
use App\Enums\AuditFolder;
use App\Enums\AuditMode;
use App\Enums\AuditPresenceState;
use App\Enums\AuditSupport;
use App\Enums\AuditWorkflow;
use App\Jobs\GenerateAuditPdf;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditControlPoint;
use App\Models\AuditFolderValidation;
use App\Models\AuditPhoto;
use App\Models\AuditPresence;
use App\Models\AuditRemark;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies what a person does on an audit, one operation at a time. Every operation carries an identifier: sending
 * the same one twice does nothing the second time, so a phone can safely resend its queue (the same identifier with
 * another content is refused). The audit is locked while an operation is applied and its revision goes up with every
 * change. The times recorded are the server's, or the phone's for an operation that waited in its queue within limits
 * (see AuditOperationLog).
 *
 * Several devices may work on the same audit: an edit of a field (note, status, support, cables, report…) carries the
 * revision the device started from, and a field changed by someone else (or on another device) since then is never
 * overwritten in silence — the operation is refused as a conflict and the person chooses to keep their version
 * (sent again with `keep_mine`) or the one already saved.
 *
 * Managers (administrators, supervisors, design office) may do everything; a technician may record what is done on
 * site, and nothing at all once the audit is validated (messages and presence aside).
 *
 * @phpstan-type Operation array<string, mixed>
 */
class AuditOperations
{
    private const array TECHNICIAN_OPERATIONS = ['box_add', 'box_set', 'box_note', 'box_status', 'box_cr', 'box_pos', 'box_pdf', 'cp_add', 'cp_note', 'cp_cr', 'cp_pdf', 'presence', 'tech_message', 'remark_ack', 'finish', 'photo_del'];

    private const array MANAGER_OPERATIONS = ['setup', 'box_admin', 'box_return', 'folder_validate', 'remark', 'validate', 'cp_remove', 'photo_move'];

    private const array OPEN_AFTER_VALIDATION = ['presence', 'tech_message', 'remark_ack', 'remark'];

    /**
     * Validation steps of the standard workflow replaced by the decisions of the design office on an audit that uses
     * the design office workflow (a box to be done again is a repair of phase 2 there).
     */
    private const array REPLACED_BY_DESIGN_OFFICE = ['folder_validate', 'remark', 'box_return'];

    /**
     * When the person did the operation being applied (see AuditOperationLog::timing()).
     */
    private Carbon $at;

    public function __construct(private AuditOperationLog $log, private LegacyBoxStatus $legacyStatus) {}

    /**
     * @param  Operation  $operation
     * @return array{duplicate: bool, revision: int}
     *
     * @throws AuditOperationException
     */
    public function apply(User $actor, Audit $audit, string $operationId, array $operation): array
    {
        $name = (string) ($operation['op'] ?? '');
        $manager = $actor->can('manage', Audit::class);

        if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $operationId) !== 1) {
            throw new AuditOperationException('operation_id_required');
        }

        if (! in_array($name, [...self::TECHNICIAN_OPERATIONS, ...self::MANAGER_OPERATIONS], true)) {
            throw new AuditOperationException('unknown_operation');
        }

        if (in_array($name, self::MANAGER_OPERATIONS, true) && ! $manager) {
            throw new AuditOperationException('forbidden', 403);
        }

        return DB::transaction(function () use ($actor, $audit, $operationId, $operation, $name, $manager): array {
            $audit = Audit::query()->lockForUpdate()->findOrFail($audit->id);
            $hash = $this->log->hash($audit, $name, $operation);

            if ($this->log->alreadyApplied($actor, $operationId, $hash)) {
                return ['duplicate' => true, 'revision' => $audit->revision];
            }

            if (! $manager && $audit->isValidated() && ! in_array($name, self::OPEN_AFTER_VALIDATION, true)) {
                throw new AuditOperationException('validated', 409);
            }

            app(FieldDataGuard::class)->ensureOperationAllowed($audit, $name);

            if ($audit->usesDesignOfficeWorkflow() && in_array($name, self::REPLACED_BY_DESIGN_OFFICE, true)) {
                throw new AuditOperationException('design_office_workflow', 409);
            }

            $field = $this->trackedField($name, $operation);

            if ($field !== null) {
                $this->guardConflict($actor, $audit, $field, $name, $operation);
            }

            $timing = $this->log->timing($actor, $operation['client_created_at'] ?? null, ($operation['queued'] ?? false) === true);
            $this->at = Carbon::instance($timing['performed_at']);
            $this->{'do'.str_replace('_', '', ucwords($name, '_'))}($actor, $audit, $operation, $manager);

            $this->log->record($actor, $audit, $operationId, $name, $hash, $timing);
            $audit->increment('revision');

            if ($field !== null) {
                DB::table('audit_field_changes')->upsert(
                    [['audit_id' => $audit->id, 'field' => $field, 'revision' => $audit->revision, 'user_id' => $actor->id, 'user_name' => $actor->name, 'device_id' => $this->device($operation), 'changed_at' => $timing['received_at']]],
                    ['audit_id', 'field'],
                    ['revision', 'user_id', 'user_name', 'device_id', 'changed_at'],
                );
            }

            return ['duplicate' => false, 'revision' => $audit->revision];
        });
    }

    /**
     * The field an edit operation changes, as a stable key, or null for what is only ever added (photos, messages,
     * presence…).
     *
     * @param  Operation  $op
     */
    private function trackedField(string $name, array $op): ?string
    {
        $box = 'box:'.BoxName::normalise((string) ($op['box'] ?? ''));
        $point = 'point:'.(int) ($op['point'] ?? 0);

        $field = match ($name) {
            'box_set' => $box.':'.(string) ($op['field'] ?? ''),
            'box_note' => $box.':note_'.(string) ($op['key'] ?? ''),
            'box_status' => $box.':status',
            'box_cr' => $box.':report',
            'cp_note' => $point.':note',
            'cp_cr' => $point.':report',
            default => null,
        };

        return $field === null ? null : mb_substr($field, 0, 120);
    }

    /**
     * Refuses an edit of a field someone else (or the same person on another device) changed after the revision the
     * device started from, unless the person chose to keep their version or the value is already the same.
     *
     * @param  Operation  $op
     *
     * @throws AuditOperationException
     */
    private function guardConflict(User $actor, Audit $audit, string $field, string $name, array $op): void
    {
        if (! is_numeric($op['base_revision'] ?? null) || ! empty($op['keep_mine'])) {
            return;
        }

        $change = DB::table('audit_field_changes')->where('audit_id', $audit->id)->where('field', $field)->first();

        if ($change === null || (int) $change->revision <= (int) $op['base_revision']) {
            return;
        }

        $device = $this->device($op);
        $sameDevice = (int) $change->user_id === $actor->id && ($device === null || $change->device_id === null || $change->device_id === $device);

        if ($sameDevice || $this->alreadySo($audit, $name, $op)) {
            return;
        }

        throw new AuditOperationException('conflict', 409, ['field' => $field, 'by' => $change->user_name, 'at' => CarbonImmutable::parse($change->changed_at)->toIso8601String()]);
    }

    /**
     * Whether the edit would leave a simple field as it already is (two people chose the same thing).
     *
     * @param  Operation  $op
     */
    private function alreadySo(Audit $audit, string $name, array $op): bool
    {
        $plain = fn (mixed $value): string => $value instanceof BackedEnum ? (string) $value->value : (string) $value;
        $box = in_array($name, ['box_set', 'box_note', 'box_status'], true) ? $audit->boxes()->where('name', BoxName::normalise((string) ($op['box'] ?? '')))->first() : null;
        $point = $name === 'cp_note' ? $audit->controlPoints()->whereKey((int) ($op['point'] ?? 0))->first() : null;
        $column = ['route' => 'note_route', 'before' => 'note_before', 'tco' => 'note_tco'][(string) ($op['key'] ?? '')] ?? null;
        $field = (string) ($op['field'] ?? '');

        return match ($name) {
            'box_note' => $box !== null && $column !== null && $plain($box->{$column}) === mb_substr((string) ($op['text'] ?? ''), 0, 2000),
            'cp_note' => $point !== null && $plain($point->note) === mb_substr((string) ($op['text'] ?? ''), 0, 2000),
            'box_status' => $box !== null && $plain($box->status) === (string) ($op['status'] ?? '') && $plain($box->reason) === trim((string) ($op['reason'] ?? '')),
            'box_set' => $box !== null && in_array($field, ['fibre_type', 'support', 'support_number', 'recheck_state'], true) && $plain($box->{$field}) === trim($plain($op['value'] ?? '')),
            default => false,
        };
    }

    /**
     * @param  Operation  $op
     */
    private function device(array $op): ?string
    {
        $device = (string) ($op['device'] ?? '');

        return preg_match('/^[A-Za-z0-9_-]{1,40}$/', $device) === 1 ? $device : null;
    }

    /**
     * @param  Operation  $op
     */
    private function doSetup(User $actor, Audit $audit, array $op, bool $manager): void
    {
        if (array_key_exists('sro', $op)) {
            $sro = mb_substr(trim((string) $op['sro']), 0, 60);
            $audit->sro = $sro === '' ? null : $sro;
            $audit->folder = mb_substr(trim($audit->cabinet_key.' '.$sro), 0, 120);
        }

        if (isset($op['mode'])) {
            $mode = AuditMode::tryFrom((string) $op['mode']) ?? throw new AuditOperationException('invalid_mode');

            if ($mode !== $audit->effectiveMode() && $this->hasActivity($audit)) {
                throw new AuditOperationException('mode_locked', 409);
            }

            $audit->mode = $mode;
        }

        if (isset($op['workflow'])) {
            $workflow = AuditWorkflow::tryFrom((string) $op['workflow']) ?? throw new AuditOperationException('invalid_workflow');

            if ($workflow !== $audit->workflow && $this->isWorkflowLocked($audit)) {
                throw new AuditOperationException('workflow_locked', 409);
            }

            $audit->workflow = $workflow;
        }

        if (isset($op['cabinet_position']) && is_array($op['cabinet_position'])) {
            $point = $this->coordinates($op['cabinet_position']['latitude'] ?? null, $op['cabinet_position']['longitude'] ?? null) ?? throw new AuditOperationException('invalid_position');
            $audit->cabinet_latitude = $point[0];
            $audit->cabinet_longitude = $point[1];
            $audit->cabinet_name = mb_substr((string) ($op['cabinet_position']['name'] ?? ''), 0, 120) ?: null;
        }

        $audit->save();
    }

    /**
     * @param  Operation  $op
     */
    private function doBoxAdd(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $name = BoxName::normalise((string) ($op['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > BoxName::MAX_LENGTH) {
            throw new AuditOperationException('invalid_name');
        }

        if ($audit->boxes()->where('name', $name)->exists()) {
            throw new AuditOperationException('exists', 409);
        }

        $kind = BoxName::classify($name) ?? AuditBoxKind::tryFrom((string) ($op['kind'] ?? '')) ?? throw new AuditOperationException('name_format');

        AuditBox::query()->create([
            'audit_id' => $audit->id, 'name' => $name, 'kind' => $kind,
            'support' => AuditSupport::tryFrom((string) ($op['support'] ?? '')), 'support_number' => mb_substr((string) ($op['support_number'] ?? ''), 0, 30) ?: null,
            'type' => mb_substr((string) ($op['type'] ?? ''), 0, 120) ?: null, 'address' => mb_substr((string) ($op['address'] ?? ''), 0, 200) ?: null,
            'source' => $manager ? AuditBoxSource::Manager : AuditBoxSource::Technician, 'created_by' => $actor->id,
        ]);
    }

    /**
     * @param  Operation  $op
     */
    private function doBoxSet(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $box = $this->box($audit, $op);
        $value = $op['value'] ?? null;

        match ((string) ($op['field'] ?? '')) {
            'fibre_type' => $box->fibre_type = $value === null || $value === '' ? null : (AuditFibreType::tryFrom((string) $value) ?? throw new AuditOperationException('bad_value')),
            'support' => $box->support = AuditSupport::tryFrom((string) $value) ?? throw new AuditOperationException('bad_value'),
            'support_number' => $box->support_number = mb_substr((string) $value, 0, 30) ?: null,
            'recheck_state' => $box->recheck_state = in_array($value, [null, '', 'nonconforming'], true) ? ($value ?: null) : throw new AuditOperationException('bad_value'),
            'cables' => $this->replaceCables($box, $value),
            default => throw new AuditOperationException('bad_field'),
        };

        $box->save();
        $this->reopen($audit, $manager);
    }

    /**
     * Asks for the PDF of a box (when the technician leaves it with a status, when a manager finishes correcting it or
     * asks for it).
     *
     * @param  Operation  $op
     */
    private function doBoxPdf(User $actor, Audit $audit, array $op, bool $manager): void
    {
        GenerateAuditPdf::dispatchAfterResponse('box', $this->box($audit, $op)->id);
    }

    /**
     * @param  Operation  $op
     */
    private function doCpPdf(User $actor, Audit $audit, array $op, bool $manager): void
    {
        GenerateAuditPdf::dispatchAfterResponse('point', $this->point($audit, $op)->id);
    }

    private function replaceCables(AuditBox $box, mixed $value): void
    {
        if (! is_array($value) || count($value) > 40) {
            throw new AuditOperationException('bad_value');
        }

        $cables = [];

        foreach ($value as $cable) {
            $code = is_array($cable) ? (string) ($cable['code'] ?? '') : '';

            if (preg_match('/^[A-Z0-9]{3,12}$/', $code) !== 1 || isset($cables[$code])) {
                throw new AuditOperationException('bad_value');
            }

            $capacity = (string) ($cable['capacity'] ?? '');
            $cables[$code] = [
                'has_no_label' => ! empty($cable['has_no_label']),
                'capacity' => in_array($capacity, (array) config('audit.capacities'), true) ? $capacity : null,
                'fibre_type' => AuditFibreType::tryFrom((string) ($cable['fibre_type'] ?? ''))?->value,
                'note' => mb_substr(trim((string) ($cable['note'] ?? '')), 0, 500) ?: null,
            ];
        }

        $box->cables()->whereNotIn('code', array_keys($cables))->delete();

        foreach ($cables as $code => $attributes) {
            $box->cables()->updateOrCreate(['code' => $code], $attributes);
        }
    }

    /**
     * @param  Operation  $op
     */
    private function doBoxNote(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $box = $this->box($audit, $op);
        $column = ['route' => 'note_route', 'before' => 'note_before', 'tco' => 'note_tco'][(string) ($op['key'] ?? '')] ?? throw new AuditOperationException('bad_key');

        $box->{$column} = mb_substr((string) ($op['text'] ?? ''), 0, 2000) ?: null;
        $box->save();
    }

    /**
     * @param  Operation  $op
     */
    private function doBoxStatus(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $box = $this->box($audit, $op);
        $raw = (string) ($op['status'] ?? '');
        $status = $raw === '' ? null : (AuditBoxStatus::tryFrom($raw) ?? throw new AuditOperationException('bad_status'));
        $reason = trim((string) ($op['reason'] ?? ''));

        if ($audit->usesDesignOfficeWorkflow()) {
            $this->legacyStatus->ensureTypable($box, $status);
        }

        if ($status === AuditBoxStatus::Anomaly && $reason === '') {
            throw new AuditOperationException('reason_required');
        }

        if (! $manager && in_array($status, [AuditBoxStatus::Ok, AuditBoxStatus::Anomaly], true)) {
            $this->requireClosingElements($audit, $box);
        }

        $box->status = $status;
        $box->reason = $status === AuditBoxStatus::Anomaly ? mb_substr($reason, 0, 1000) : null;
        $box->returned_comment = $box->returned_by = $box->returned_by_name = $box->returned_at = null;

        if ($status !== null) {
            $box->status_at = $this->at;
            $box->status_by = $actor->id;
            $box->status_by_name = $actor->name;
        }

        $box->save();
        $this->reopen($audit, $manager);

        if ($status !== null) {
            GenerateAuditPdf::dispatchAfterResponse('box', $box->id);
        }
    }

    /**
     * In the full mode a box is closed (OK or anomaly) once its « before works » comment, its route comment and, when
     * its list of articles exists, its report are filled in.
     *
     * @throws AuditOperationException
     */
    private function requireClosingElements(Audit $audit, AuditBox $box): void
    {
        if ($audit->effectiveMode() !== AuditMode::Full) {
            return;
        }

        if (trim((string) $box->note_before) === '') {
            throw new AuditOperationException('before_required');
        }

        if (trim((string) $box->note_route) === '') {
            throw new AuditOperationException('route_required');
        }

        if (($box->report_items ?? []) === [] && app(AuditCatalog::class)->boxNeedsReport($box)) {
            throw new AuditOperationException('report_required');
        }
    }

    /**
     * @param  Operation  $op
     */
    private function doBoxCr(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $box = $this->box($audit, $op);
        $box->report_items = $this->reportItems($op['items'] ?? []);
        $box->report_at = $this->at;
        $box->report_by = $actor->id;
        $box->report_by_name = $actor->name;
        $box->save();
    }

    /**
     * @param  Operation  $op
     */
    private function doCpCr(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $point = $this->point($audit, $op);
        $point->report_items = $this->reportItems($op['items'] ?? []);
        $point->report_at = $this->at;
        $point->report_by = $actor->id;
        $point->report_by_name = $actor->name;
        $point->save();
    }

    /**
     * The list of articles of a report, whole: id, frozen label and quantity (at most 80 lines).
     *
     * @return list<array{id: string, label: string, qty: int}>
     */
    private function reportItems(mixed $items): array
    {
        $out = [];

        foreach ((array) $items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = mb_substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($item['id'] ?? '')), 0, 40);
            $label = mb_substr(trim((string) ($item['label'] ?? '')), 0, 160);
            $quantity = (int) ($item['qty'] ?? 0);

            if ($id !== '' && $label !== '' && $quantity >= 1) {
                $out[] = ['id' => $id, 'label' => $label, 'qty' => min($quantity, 999)];
            }

            if (count($out) >= 80) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  Operation  $op
     */
    private function doBoxPos(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $box = $this->box($audit, $op);
        $point = $this->coordinates($op['latitude'] ?? null, $op['longitude'] ?? null) ?? throw new AuditOperationException('position_required');

        $box->confirmed_latitude = $point[0];
        $box->confirmed_longitude = $point[1];
        $box->confirmed_accuracy = $this->accuracy($op['accuracy'] ?? null);
        $box->confirmed_at = $this->at;
        $box->confirmed_by = $actor->id;
        $box->confirmed_by_name = $actor->name;
        $box->save();

        app(BoxPositions::class)->recordField($actor, $box, $point[0], $point[1], $box->confirmed_accuracy);
    }

    /**
     * @param  Operation  $op
     */
    private function doCpAdd(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($op['name'] ?? '')));

        if ($name === '' || mb_strlen($name) > 70) {
            throw new AuditOperationException('invalid_name');
        }

        if ($audit->controlPoints()->where('name', $name)->exists()) {
            throw new AuditOperationException('exists', 409);
        }

        $audit->controlPoints()->create(['name' => $name, 'subfolders' => [], 'is_custom' => true]);
    }

    /**
     * @param  Operation  $op
     */
    private function doCpNote(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $point = $this->point($audit, $op);
        $point->note = mb_substr((string) ($op['text'] ?? ''), 0, 2000) ?: null;
        $point->save();
    }

    /**
     * @param  Operation  $op
     */
    private function doCpRemove(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $point = $this->point($audit, $op);

        if ($point->photos()->exists()) {
            throw new AuditOperationException('has_photos', 409);
        }

        $point->delete();
    }

    /**
     * @param  Operation  $op
     */
    private function doPresence(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $previous = AuditPresence::query()->where('audit_id', $audit->id)->where('user_id', $actor->id)->first();
        $raw = $op['state'] ?? null;

        if ($raw === null) {
            AuditPresence::query()->where('audit_id', $audit->id)->where('user_id', $actor->id)->delete();

            return;
        }

        $state = AuditPresenceState::tryFrom((string) $raw) ?? throw new AuditOperationException('bad_state');
        $box = $this->box($audit, $op);
        $point = $this->coordinates($op['latitude'] ?? null, $op['longitude'] ?? null);
        $stays = $previous !== null && $previous->audit_box_id === $box->id && $previous->state === AuditPresenceState::OnBox && $state === AuditPresenceState::OnBox;

        AuditPresence::query()->updateOrCreate(['audit_id' => $audit->id, 'user_id' => $actor->id], [
            'audit_box_id' => $box->id, 'state' => $state, 'latitude' => $point[0] ?? null, 'longitude' => $point[1] ?? null,
            'accuracy' => $point === null ? null : $this->accuracy($op['accuracy'] ?? null), 'is_automatic' => ! empty($op['automatic']),
            'since' => $stays ? $previous->since : $this->at, 'seen_at' => $this->at,
        ]);
    }

    /**
     * @param  Operation  $op
     */
    private function doTechMessage(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $this->addRemark($actor, $this->box($audit, $op), $op, 'tech');
    }

    /**
     * @param  Operation  $op
     */
    private function doRemark(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $this->addRemark($actor, $this->box($audit, $op), $op, 'office');
    }

    /**
     * @param  Operation  $op
     */
    private function addRemark(User $actor, AuditBox $box, array $op, string $side): void
    {
        $text = mb_substr(trim((string) ($op['text'] ?? '')), 0, 1000);

        if ($text === '') {
            throw new AuditOperationException('text_required');
        }

        $reference = preg_match('/^r_[a-z0-9]{6,20}$/', (string) ($op['id'] ?? '')) === 1 ? (string) $op['id'] : 'r_'.bin2hex(random_bytes(4));

        if (AuditRemark::query()->where('client_ref', $reference)->exists()) {
            return;
        }

        $box->remarks()->create(['client_ref' => $reference, 'author_side' => $side, 'user_id' => $actor->id, 'user_name' => $actor->name, 'text' => $text, 'created_at' => $this->at]);

        $keep = $box->remarks()->reorder('id', 'desc')->limit(60)->pluck('id');
        $box->remarks()->whereNotIn('id', $keep)->delete();
    }

    /**
     * @param  Operation  $op
     */
    private function doRemarkAck(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $box = $this->box($audit, $op);

        $box->remarks()->where('client_ref', (string) ($op['id'] ?? ''))->whereNull('acknowledged_at')
            ->update(['acknowledged_by' => $actor->id, 'acknowledged_by_name' => $actor->name, 'acknowledged_at' => $this->at]);
    }

    /**
     * @param  Operation  $op
     */
    private function doFinish(User $actor, Audit $audit, array $op, bool $manager): void
    {
        if (! $manager) {
            $cabinet = $audit->controlPoints()->where('kind', 'cabinet')->first();

            if ($cabinet !== null && ($cabinet->report_items ?? []) === [] && app(AuditCatalog::class)->cabinetNeedsReport($cabinet)) {
                throw new AuditOperationException('cabinet_report_required');
            }
        }

        $audit->update(['finished_by' => $actor->id, 'finished_by_name' => $actor->name, 'finished_at' => $this->at, 'validated_by' => null, 'validated_by_name' => null, 'validated_at' => null]);
        AuditPresence::query()->where('audit_id', $audit->id)->where('user_id', $actor->id)->delete();
    }

    /**
     * @param  Operation  $op
     */
    private function doValidate(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $audit->update(['validated_by' => $actor->id, 'validated_by_name' => $actor->name, 'validated_at' => $this->at]);
    }

    /**
     * @param  Operation  $op
     */
    private function doBoxReturn(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $box = $this->box($audit, $op);
        $comment = mb_substr(trim((string) ($op['comment'] ?? '')), 0, 1000);

        if ($comment === '') {
            throw new AuditOperationException('comment_required');
        }

        $box->update(['returned_comment' => $comment, 'returned_by' => $actor->id, 'returned_by_name' => $actor->name, 'returned_at' => $this->at]);
        $audit->update(['finished_by' => null, 'finished_by_name' => null, 'finished_at' => null, 'validated_by' => null, 'validated_by_name' => null, 'validated_at' => null]);
    }

    /**
     * @param  Operation  $op
     */
    private function doBoxAdmin(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $box = $this->box($audit, $op);

        if (! empty($op['removed'])) {
            app(FieldDataGuard::class)->ensureBoxRemovable($box);
        }

        if (array_key_exists('removed', $op)) {
            $box->removed_at = $op['removed'] ? $this->at : null;
            $box->removed_by = $op['removed'] ? $actor->id : null;
        }

        foreach (['type' => 120, 'address' => 200] as $field => $limit) {
            if (isset($op[$field])) {
                $box->{$field} = mb_substr((string) $op[$field], 0, $limit) ?: null;
            }
        }

        if (isset($op['kind'])) {
            $box->kind = AuditBoxKind::tryFrom((string) $op['kind']) ?? throw new AuditOperationException('bad_value');
        }

        $box->save();
    }

    /**
     * @param  Operation  $op
     */
    private function doFolderValidate(User $actor, Audit $audit, array $op, bool $manager): void
    {
        $box = $this->box($audit, $op);
        $folder = AuditFolder::tryFrom((string) ($op['folder'] ?? '')) ?? throw new AuditOperationException('bad_folder');

        if (empty($op['valid'])) {
            AuditFolderValidation::query()->where('audit_box_id', $box->id)->where('folder', $folder->value)->delete();

            return;
        }

        $count = AuditPhoto::query()->where('audit_box_id', $box->id)->where('folder', $folder->value)->count();

        if ($count < 1) {
            throw new AuditOperationException('no_photos');
        }

        AuditFolderValidation::query()->updateOrCreate(['audit_box_id' => $box->id, 'folder' => $folder->value], [
            'user_id' => $actor->id, 'user_name' => $actor->name, 'photos_count' => $count, 'validated_at' => $this->at,
        ]);
    }

    /**
     * @param  Operation  $op
     */
    private function doPhotoDel(User $actor, Audit $audit, array $op, bool $manager): void
    {
        app(AuditPhotoStore::class)->delete($actor, $audit, (int) ($op['photo'] ?? 0), $manager);
    }

    /**
     * @param  Operation  $op
     */
    private function doPhotoMove(User $actor, Audit $audit, array $op, bool $manager): void
    {
        app(AuditPhotoStore::class)->move($audit, $this->box($audit, $op), (string) ($op['folder'] ?? ''), isset($op['subfolder']) ? (string) $op['subfolder'] : null, array_values(array_unique(array_map('intval', (array) ($op['photos'] ?? [])))));
    }

    /**
     * A change made by a technician reopens an audit they had finished; a manager's correction does not.
     */
    private function reopen(Audit $audit, bool $manager): void
    {
        if (! $manager && $audit->isFinished()) {
            $audit->update(['finished_by' => null, 'finished_by_name' => null, 'finished_at' => null]);
        }
    }

    /**
     * @param  Operation  $op
     */
    private function box(Audit $audit, array $op): AuditBox
    {
        return $audit->boxes()->where('name', BoxName::normalise((string) ($op['box'] ?? '')))->first() ?? throw new AuditOperationException('box_not_found', 404);
    }

    /**
     * @param  Operation  $op
     */
    private function point(Audit $audit, array $op): AuditControlPoint
    {
        return $audit->controlPoints()->whereKey((int) ($op['point'] ?? 0))->first() ?? throw new AuditOperationException('point_not_found', 404);
    }

    /**
     * A mode can no longer change once photos, statuses or confirmed positions exist.
     */
    private function hasActivity(Audit $audit): bool
    {
        return $audit->photos()->exists() || $audit->boxes()->where(fn ($query) => $query->whereNotNull('status')->orWhereNotNull('confirmed_at'))->exists();
    }

    /**
     * The workflow of an audit is chosen before any field activity and never changes afterwards (once a box was started
     * in the design office workflow, or photos, statuses or confirmed positions exist).
     */
    public function isWorkflowLocked(Audit $audit): bool
    {
        return $audit->workflow_locked_at !== null || $this->hasActivity($audit) || $audit->boxes()->whereNotNull('responsible_id')->exists();
    }

    /**
     * @return array{string, string}|null Latitude and longitude with seven decimals, or null when not usable.
     */
    private function coordinates(mixed $latitude, mixed $longitude): ?array
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        if (abs($lat) > 90 || abs($lng) > 180 || (abs($lat) < 1e-9 && abs($lng) < 1e-9)) {
            return null;
        }

        return [number_format($lat, 7, '.', ''), number_format($lng, 7, '.', '')];
    }

    private function accuracy(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round(min(9999, max(0, (float) $value))) : null;
    }
}
