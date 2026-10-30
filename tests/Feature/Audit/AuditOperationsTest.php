<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditOperationException;
use App\Audit\AuditOperationLog;
use App\Audit\AuditOperations;
use App\Audit\OpenAudit;
use App\Enums\AuditBoxStatus;
use App\Enums\AuditFolder;
use App\Enums\AuditMode;
use App\Enums\AuditPresenceState;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Http\Controllers\AuditOperationController;
use App\Http\Middleware\EnsureCurrentSyncProtocol;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditPhoto;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\WorkSite;
use App\Settings\ModuleSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $manager;

    private User $technician;

    private User $other;

    private Audit $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader(EnsureCurrentSyncProtocol::HEADER, (string) EnsureCurrentSyncProtocol::VERSION);

        config(['audit.box_suffixes' => ['FT']]);
        $this->seed(RoleSeeder::class);
        $this->agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $this->agency->id, 'name' => 'Sam']);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id, 'name' => 'Alice']);
        $this->other = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id, 'name' => 'Bruno']);
        $this->audit = app(OpenAudit::class)->handle($this->manager, 'pm 153');
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array{duplicate: bool, revision: int}
     */
    private function send(User $user, array $op, ?string $id = null): array
    {
        $this->actingAs($user);

        return app(AuditOperations::class)->apply($user, $this->audit->refresh(), $id ?? 'o_'.bin2hex(random_bytes(5)), $op);
    }

    private function reason(User $user, array $op): string
    {
        try {
            $this->send($user, $op);
        } catch (AuditOperationException $exception) {
            return $exception->reason.':'.$exception->status;
        }

        return 'none';
    }

    private function box(string $name = 'BPE-0001-FT'): AuditBox
    {
        $this->send($this->technician, ['op' => 'box_add', 'name' => $name]);

        return $this->audit->boxes()->where('name', $name)->firstOrFail();
    }

    private function writeClosingNotes(string $name = 'BPE-0001-FT'): void
    {
        $this->send($this->technician, ['op' => 'box_note', 'box' => $name, 'key' => 'before', 'text' => 'Clean before works']);
        $this->send($this->technician, ['op' => 'box_note', 'box' => $name, 'key' => 'route', 'text' => 'RAS']);
    }

    public function test_opening_an_audit_creates_the_default_control_points_once_and_links_the_planned_work_sites(): void
    {
        $site = WorkSite::factory()->create(['agency_id' => $this->agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM-153']);
        $again = app(OpenAudit::class)->handle($this->manager, 'PM-153');

        $this->assertSame('PM-153', $again->cabinet_key);
        $this->assertSame(['Cabinet', 'Satellite chamber', 'Clean-up PM-153'], $again->controlPoints()->orderBy('id')->pluck('name')->all());
        $this->assertSame(['before', 'after'], $again->controlPoints()->where('kind', 'cabinet')->first()->subfolders);
        $this->assertSame($again->id, $site->refresh()->audit_id);
        $this->assertSame(3, app(OpenAudit::class)->handle($this->manager, 'PM-153')->controlPoints()->count());
    }

    public function test_the_same_operation_id_is_applied_once(): void
    {
        $first = $this->send($this->technician, ['op' => 'box_add', 'name' => 'BPE-0001'], 'o_same');
        $second = $this->send($this->technician, ['op' => 'box_add', 'name' => 'BPE-0001'], 'o_same');

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['revision'], $second['revision']);
        $this->assertSame(1, $this->audit->boxes()->count());
        $this->assertSame($first['revision'] + 1, $this->send($this->technician, ['op' => 'box_add', 'name' => 'BPE-0002'])['revision']);

        $this->assertSame('operation_id_required:422', (function () {
            try {
                app(AuditOperations::class)->apply($this->technician, $this->audit, 'bad id!', ['op' => 'box_add', 'name' => 'BPE-0009']);
            } catch (AuditOperationException $e) {
                return $e->reason.':'.$e->status;
            }

            return 'none';
        })());
    }

    public function test_reusing_an_operation_id_with_another_content_is_refused_and_ids_belong_to_each_person(): void
    {
        $this->box();
        $note = ['op' => 'box_note', 'box' => 'BPE-0001-FT', 'key' => 'route', 'text' => 'RAS'];

        $this->assertFalse($this->send($this->technician, $note + ['queued' => false], 'o_reused')['duplicate']);
        $this->assertTrue($this->send($this->technician, $note + ['queued' => true], 'o_reused')['duplicate']);

        $this->assertSame('operation_id_reused:422', (function () use ($note) {
            try {
                $this->send($this->technician, ['text' => 'Something else'] + $note, 'o_reused');
            } catch (AuditOperationException $e) {
                return $e->reason.':'.$e->status;
            }

            return 'none';
        })());
        $this->assertSame('RAS', $this->audit->boxes()->first()->note_route);

        $this->assertFalse($this->send($this->other, ['text' => 'Other person'] + $note, 'o_reused')['duplicate']);
        $this->assertSame(['o_reused'], DB::table('audit_applied_operations')->where('user_id', $this->other->id)->pluck('operation_id')->all());

        $url = route('audits.operations', ['cabinet' => 'PM153']);
        WorkSite::factory()->create(['agency_id' => $this->agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM 153', 'lead_technician_id' => $this->technician->id]);
        $this->actingAs($this->technician)->postJson($url, ['op' => ['text' => 'Changed', 'operation_id' => 'o_reused'] + $note])
            ->assertStatus(422)->assertJson(['ok' => false, 'error' => 'operation_id_reused']);
    }

    public function test_the_time_of_the_phone_is_kept_only_for_queued_operations_within_limits(): void
    {
        $this->travelTo(now()->subHour());
        $box = $this->box();
        $this->travelBack();
        $this->freezeSecond();
        $message = fn (string $text, array $timing) => $this->send($this->technician, ['op' => 'tech_message', 'box' => 'BPE-0001-FT', 'text' => $text] + $timing, 'o_'.md5($text));
        $timeOf = fn (string $text) => $box->remarks()->where('text', $text)->sole()->created_at;
        $tenMinutesAgo = now()->subMinutes(10);

        $message('Queued', ['client_created_at' => $tenMinutesAgo->toIso8601String(), 'queued' => true]);
        $this->assertTrue($timeOf('Queued')->equalTo($tenMinutesAgo));

        $message('Online', ['client_created_at' => $tenMinutesAgo->toIso8601String()]);
        $this->assertTrue($timeOf('Online')->equalTo(now()));

        $message('Older than the last one', ['client_created_at' => now()->subMinutes(20)->toIso8601String(), 'queued' => true]);
        $message('Too old', ['client_created_at' => now()->subDays(AuditOperationLog::CLIENT_TIME_TRUSTED_DAYS)->subMinute()->toIso8601String(), 'queued' => true]);
        $message('Just now', ['client_created_at' => now()->subMinute()->toIso8601String(), 'queued' => true]);
        $message('In the future', ['client_created_at' => now()->addHour()->toIso8601String(), 'queued' => true]);
        $message('Garbage', ['client_created_at' => 'yesterday-ish', 'queued' => true]);

        foreach (['Older than the last one', 'Too old', 'Just now', 'In the future', 'Garbage'] as $text) {
            $this->assertTrue($timeOf($text)->equalTo(now()), $text);
        }

        $online = DB::table('audit_applied_operations')->where('operation_id', 'o_'.md5('Online'))->sole();
        $queued = DB::table('audit_applied_operations')->where('operation_id', 'o_'.md5('Queued'))->sole();
        $this->assertFalse((bool) $online->queued);
        $this->assertSame(600, (int) $online->clock_gap_seconds);
        $this->assertTrue(CarbonImmutable::parse($online->received_at)->equalTo(now()));
        $this->assertTrue(CarbonImmutable::parse($online->performed_at)->equalTo(now()));
        $this->assertTrue((bool) $queued->queued);
        $this->assertTrue(CarbonImmutable::parse($queued->performed_at)->equalTo($tenMinutesAgo));
        $this->assertSame($this->technician->id, (int) $queued->user_id);
        $this->assertSame('tech_message', $queued->type);
        $this->assertNull(DB::table('audit_applied_operations')->where('operation_id', 'o_'.md5('Garbage'))->value('client_created_at'));
    }

    public function test_the_time_of_the_phone_is_trusted_up_to_seven_days_before_the_reception(): void
    {
        $this->travelTo(now()->subDays(AuditOperationLog::CLIENT_TIME_TRUSTED_DAYS + 1));
        $box = $this->box();
        $this->travelBack();
        $this->freezeSecond();
        $timeOf = fn (string $text) => $box->remarks()->where('text', $text)->sole()->created_at;
        $sixDaysAgo = now()->subDays(AuditOperationLog::CLIENT_TIME_TRUSTED_DAYS - 1);

        $this->send($this->technician, ['op' => 'tech_message', 'box' => 'BPE-0001-FT', 'text' => 'Six days ago', 'client_created_at' => $sixDaysAgo->toIso8601String(), 'queued' => true]);
        $this->send($this->other, ['op' => 'tech_message', 'box' => 'BPE-0001-FT', 'text' => 'Over seven days ago', 'client_created_at' => now()->subDays(AuditOperationLog::CLIENT_TIME_TRUSTED_DAYS)->subMinute()->toIso8601String(), 'queued' => true]);

        $this->assertSame(7, AuditOperationLog::CLIENT_TIME_TRUSTED_DAYS);
        $this->assertTrue($timeOf('Six days ago')->equalTo($sixDaysAgo));
        $this->assertTrue($timeOf('Over seven days ago')->equalTo(now()));
    }

    public function test_applied_operations_are_forgotten_after_the_retention_period(): void
    {
        $this->box();
        $this->travel(AuditOperationLog::RETENTION_DAYS + 1)->days();
        $this->send($this->technician, ['op' => 'box_add', 'name' => 'BPE-0002-FT']);

        $this->artisan('audits:purge-operations')->assertSuccessful();

        $this->assertSame(1, DB::table('audit_applied_operations')->count());
    }

    public function test_the_endpoint_refuses_old_phone_code_and_oversized_bodies(): void
    {
        WorkSite::factory()->create(['agency_id' => $this->agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM 153', 'lead_technician_id' => $this->technician->id]);
        $url = route('audits.operations', ['cabinet' => 'PM153']);
        $op = ['op' => ['op' => 'box_add', 'name' => 'BPE-0001', 'operation_id' => 'o_version1']];

        $this->actingAs($this->technician)->postJson($url, $op, [EnsureCurrentSyncProtocol::HEADER => (string) (EnsureCurrentSyncProtocol::VERSION - 1)])
            ->assertStatus(426)->assertJson(['ok' => false, 'error' => 'update_required', 'message' => __('audit.errors.update_required')]);
        $this->withoutHeader(EnsureCurrentSyncProtocol::HEADER)->actingAs($this->technician)->postJson($url, $op)->assertStatus(426);
        $this->assertSame(0, $this->audit->boxes()->count());

        $this->withHeader(EnsureCurrentSyncProtocol::HEADER, (string) EnsureCurrentSyncProtocol::VERSION);
        $this->actingAs($this->technician)->postJson($url, ['op' => ['op' => 'tech_message', 'box' => 'BPE-0001', 'text' => str_repeat('x', AuditOperationController::MAX_BODY_BYTES), 'operation_id' => 'o_big1']])
            ->assertStatus(413)->assertJson(['ok' => false, 'error' => 'payload_too_large']);
        $this->actingAs($this->technician)->postJson($url, $op)->assertOk();
    }

    public function test_the_phone_and_the_server_speak_the_same_protocol_version(): void
    {
        preg_match('/export const PROTOCOL_VERSION = (\d+);/', (string) file_get_contents(resource_path('js/audit/sync.js')), $match);

        $this->assertSame((string) EnsureCurrentSyncProtocol::VERSION, $match[1] ?? null);
    }

    public function test_manager_only_operations_are_refused_to_technicians(): void
    {
        $this->box();

        foreach (['setup', 'box_admin', 'box_return', 'folder_validate', 'remark', 'validate', 'cp_remove'] as $op) {
            $this->assertSame('forbidden:403', $this->reason($this->technician, ['op' => $op, 'box' => 'BPE-0001-FT']), $op);
        }

        $this->assertSame('unknown_operation:422', $this->reason($this->manager, ['op' => 'drop_everything']));
        $this->assertSame('unknown_operation:422', $this->reason($this->manager, []));
    }

    public function test_adding_a_box_follows_the_name_rules(): void
    {
        $this->assertSame('invalid_name:422', $this->reason($this->technician, ['op' => 'box_add', 'name' => '   ']));
        $this->assertSame('invalid_name:422', $this->reason($this->technician, ['op' => 'box_add', 'name' => str_repeat('A', 61)]));
        $this->assertSame('name_format:422', $this->reason($this->technician, ['op' => 'box_add', 'name' => 'Some box']));

        $this->send($this->technician, ['op' => 'box_add', 'name' => 'Some box', 'kind' => 'jd', 'support' => 'pole', 'support_number' => 'P12']);
        $custom = $this->audit->boxes()->where('name', 'SOME BOX')->first();
        $this->assertSame('jd', $custom->kind->value);
        $this->assertSame('pole', $custom->support->value);
        $this->assertSame('technician', $custom->source->value);

        $this->send($this->manager, ['op' => 'box_add', 'name' => 'bpe-0007-ft']);
        $this->assertSame('BPE-0007-FT', $this->audit->boxes()->where('name', 'BPE-0007-FT')->first()->name);
        $this->assertSame('manager', $this->audit->boxes()->where('name', 'BPE-0007-FT')->first()->source->value);
        $this->assertSame('exists:409', $this->reason($this->technician, ['op' => 'box_add', 'name' => 'bpe-0007-ft']));
    }

    public function test_fields_cables_and_notes_are_validated(): void
    {
        $this->box();
        $set = fn (string $field, mixed $value) => $this->send($this->technician, ['op' => 'box_set', 'box' => 'bpe-0001-ft', 'field' => $field, 'value' => $value]);

        $set('fibre_type', 'g657');
        $set('support', 'facade');
        $set('support_number', str_repeat('9', 40));
        $box = $this->audit->boxes()->first();
        $this->assertSame('g657', $box->fibre_type->value);
        $this->assertSame('facade', $box->support->value);
        $this->assertSame(30, strlen($box->support_number));

        $set('fibre_type', '');
        $this->assertNull($box->refresh()->fibre_type);

        $this->assertSame('bad_value:422', $this->reason($this->technician, ['op' => 'box_set', 'box' => 'BPE-0001-FT', 'field' => 'support', 'value' => 'roof']));
        $this->assertSame('bad_field:422', $this->reason($this->technician, ['op' => 'box_set', 'box' => 'BPE-0001-FT', 'field' => 'status', 'value' => 'ok']));
        $this->assertSame('box_not_found:404', $this->reason($this->technician, ['op' => 'box_set', 'box' => 'BPE-9999', 'field' => 'support', 'value' => 'pole']));

        $set('cables', [['code' => 'CAB1X', 'capacity' => '12FO', 'fibre_type' => 'g657'], ['code' => 'CAB2X', 'capacity' => '77FO', 'has_no_label' => true, 'note' => 'x']]);
        $this->assertSame(['CAB1X' => '12FO', 'CAB2X' => null], $box->cables()->pluck('capacity', 'code')->all());
        $set('cables', [['code' => 'CAB2X']]);
        $this->assertSame(['CAB2X'], $box->cables()->pluck('code')->all());
        $this->assertSame('bad_value:422', $this->reason($this->technician, ['op' => 'box_set', 'box' => 'BPE-0001-FT', 'field' => 'cables', 'value' => [['code' => 'ab']]]));
        $this->assertSame('bad_value:422', $this->reason($this->technician, ['op' => 'box_set', 'box' => 'BPE-0001-FT', 'field' => 'cables', 'value' => [['code' => 'CAB1X'], ['code' => 'CAB1X']]]));

        $this->send($this->technician, ['op' => 'box_note', 'box' => 'BPE-0001-FT', 'key' => 'route', 'text' => 'RAS']);
        $this->assertSame('RAS', $box->refresh()->note_route);
        $this->assertSame('bad_key:422', $this->reason($this->technician, ['op' => 'box_note', 'box' => 'BPE-0001-FT', 'key' => 'other', 'text' => 'x']));
    }

    public function test_statuses_need_a_reason_for_an_anomaly_and_reopen_a_finished_audit_only_for_technicians(): void
    {
        $box = $this->box();
        $this->writeClosingNotes();
        $this->send($this->technician, ['op' => 'finish']);
        $this->assertTrue($this->audit->refresh()->isFinished());

        $this->assertSame('reason_required:422', $this->reason($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001-FT', 'status' => 'anomaly', 'reason' => ' ']));
        $this->assertSame('bad_status:422', $this->reason($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001-FT', 'status' => 'done']));

        $this->send($this->manager, ['op' => 'box_status', 'box' => 'BPE-0001-FT', 'status' => 'ok']);
        $this->assertTrue($this->audit->refresh()->isFinished());

        $this->send($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001-FT', 'status' => 'anomaly', 'reason' => 'Cracked']);
        $box->refresh();
        $this->assertSame(AuditBoxStatus::Anomaly, $box->status);
        $this->assertSame('Cracked', $box->reason);
        $this->assertSame('Alice', $box->status_by_name);
        $this->assertFalse($this->audit->refresh()->isFinished());

        $this->send($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001-FT', 'status' => 'ok', 'reason' => 'ignored']);
        $this->assertNull($box->refresh()->reason);

        $this->send($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001-FT', 'status' => '']);
        $this->assertNull($box->refresh()->status);
    }

    public function test_a_returned_box_loses_its_final_status_and_reopens_the_audit(): void
    {
        $box = $this->box();
        $this->writeClosingNotes();
        $this->send($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001-FT', 'status' => 'ok']);
        $this->send($this->technician, ['op' => 'finish']);
        $this->send($this->manager, ['op' => 'validate']);
        $this->assertTrue($this->audit->refresh()->isValidated());

        $this->assertSame('comment_required:422', $this->reason($this->manager, ['op' => 'box_return', 'box' => 'BPE-0001-FT', 'comment' => '']));
        $this->send($this->manager, ['op' => 'box_return', 'box' => 'BPE-0001-FT', 'comment' => 'Redo the closed box']);

        $this->assertTrue($box->refresh()->isReturned());
        $this->assertFalse($box->isFinal());
        $this->assertFalse($this->audit->refresh()->isFinished() || $this->audit->isValidated());

        $this->send($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001-FT', 'status' => 'ok']);
        $this->assertFalse($box->refresh()->isReturned());
        $this->assertTrue($box->isFinal());
    }

    public function test_a_validated_audit_refuses_technicians_but_not_their_messages_or_presence(): void
    {
        $this->box();
        $this->send($this->technician, ['op' => 'finish']);
        $this->send($this->manager, ['op' => 'validate']);

        foreach ([['op' => 'box_add', 'name' => 'BPE-0002'], ['op' => 'box_status', 'box' => 'BPE-0001-FT', 'status' => 'ok'], ['op' => 'cp_add', 'name' => 'Extra'], ['op' => 'finish']] as $op) {
            $this->assertSame('validated:409', $this->reason($this->technician, $op));
        }

        $this->send($this->technician, ['op' => 'tech_message', 'box' => 'BPE-0001-FT', 'text' => 'Hello']);
        $this->send($this->technician, ['op' => 'presence', 'state' => 'on_box', 'box' => 'BPE-0001-FT']);
        $this->send($this->manager, ['op' => 'box_add', 'name' => 'BPE-0002']);

        $this->assertSame(2, $this->audit->boxes()->count());
    }

    public function test_the_mode_is_locked_once_there_is_activity(): void
    {
        $this->send($this->manager, ['op' => 'setup', 'mode' => 'optical', 'sro' => 'SRO-1', 'cabinet_position' => ['latitude' => 43.93, 'longitude' => 2.14, 'name' => 'Cabinet']]);
        $this->audit->refresh();
        $this->assertSame(AuditMode::Optical, $this->audit->mode);
        $this->assertSame('SRO-1', $this->audit->sro);
        $this->assertSame('PM153 SRO-1', $this->audit->folder);
        $this->assertSame('43.930000', $this->audit->cabinet_latitude);

        $this->send($this->manager, ['op' => 'setup', 'mode' => 'full']);
        $this->assertSame(AuditMode::Full, $this->audit->refresh()->mode);

        $this->box();
        $this->send($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001-FT', 'status' => 'partial']);
        $this->assertSame('mode_locked:409', $this->reason($this->manager, ['op' => 'setup', 'mode' => 'optical']));
        $this->send($this->manager, ['op' => 'setup', 'mode' => 'full']);
        $this->assertSame('invalid_mode:422', $this->reason($this->manager, ['op' => 'setup', 'mode' => 'weird']));
        $this->assertSame('invalid_position:422', $this->reason($this->manager, ['op' => 'setup', 'cabinet_position' => ['latitude' => 0, 'longitude' => 0]]));
    }

    public function test_the_position_must_be_a_real_one(): void
    {
        $box = $this->box();

        foreach ([[0, 0], [91, 2], [43, 181], ['x', 2], [null, null]] as [$lat, $lng]) {
            $this->assertSame('position_required:422', $this->reason($this->technician, ['op' => 'box_pos', 'box' => 'BPE-0001-FT', 'latitude' => $lat, 'longitude' => $lng]));
        }

        $this->send($this->technician, ['op' => 'box_pos', 'box' => 'BPE-0001-FT', 'latitude' => 43.9298123456, 'longitude' => 2.148, 'accuracy' => 123456]);
        $box->refresh();
        $this->assertSame('43.9298123', $box->confirmed_latitude);
        $this->assertSame(9999, $box->confirmed_accuracy);
        $this->assertSame('Alice', $box->confirmed_by_name);
    }

    public function test_presence_keeps_the_arrival_time_while_staying_on_the_same_box(): void
    {
        $this->box();
        $this->box('BPE-0002-FT');

        $this->send($this->technician, ['op' => 'presence', 'state' => 'en_route', 'box' => 'BPE-0001-FT']);
        $this->send($this->technician, ['op' => 'presence', 'state' => 'on_box', 'box' => 'BPE-0001-FT', 'latitude' => 43.9, 'longitude' => 2.1, 'accuracy' => 8, 'automatic' => true]);
        $arrived = $this->audit->presences()->sole();
        $this->assertSame(AuditPresenceState::OnBox, $arrived->state);
        $this->assertTrue($arrived->is_automatic);

        $this->travel(5)->minutes();
        $this->send($this->technician, ['op' => 'presence', 'state' => 'on_box', 'box' => 'BPE-0001-FT']);
        $this->assertTrue($this->audit->presences()->sole()->since->equalTo($arrived->since));
        $this->assertTrue($this->audit->presences()->sole()->seen_at->greaterThan($arrived->seen_at));

        $this->send($this->technician, ['op' => 'presence', 'state' => 'on_box', 'box' => 'BPE-0002-FT']);
        $this->assertTrue($this->audit->presences()->sole()->since->greaterThan($arrived->since));

        $this->send($this->other, ['op' => 'presence', 'state' => 'en_route', 'box' => 'BPE-0002-FT']);
        $this->assertSame(2, $this->audit->presences()->count());

        $this->assertSame('bad_state:422', $this->reason($this->technician, ['op' => 'presence', 'state' => 'flying', 'box' => 'BPE-0001-FT']));
        $this->assertSame('box_not_found:404', $this->reason($this->technician, ['op' => 'presence', 'state' => 'on_box', 'box' => 'BPE-7777']));

        $this->send($this->technician, ['op' => 'presence', 'state' => null]);
        $this->assertSame(1, $this->audit->presences()->count());
        $this->send($this->other, ['op' => 'finish']);
        $this->assertSame(0, $this->audit->presences()->count());
    }

    public function test_messages_are_deduplicated_limited_and_acknowledged(): void
    {
        $box = $this->box();

        $this->assertSame('text_required:422', $this->reason($this->technician, ['op' => 'tech_message', 'box' => 'BPE-0001-FT', 'text' => ' ']));
        $this->send($this->technician, ['op' => 'tech_message', 'box' => 'BPE-0001-FT', 'text' => 'On it', 'id' => 'r_abcdef12']);
        $this->send($this->technician, ['op' => 'tech_message', 'box' => 'BPE-0001-FT', 'text' => 'On it', 'id' => 'r_abcdef12']);
        $this->assertSame(1, $box->remarks()->count());

        $this->send($this->manager, ['op' => 'remark', 'box' => 'BPE-0001-FT', 'text' => 'Missing photo', 'id' => 'r_office123']);
        $this->send($this->technician, ['op' => 'remark_ack', 'box' => 'BPE-0001-FT', 'id' => 'r_office123']);
        $remark = $box->remarks()->where('client_ref', 'r_office123')->first();
        $this->assertSame('office', $remark->author_side);
        $this->assertSame('Alice', $remark->acknowledged_by_name);

        $this->travel(1)->minute();
        $this->send($this->other, ['op' => 'remark_ack', 'box' => 'BPE-0001-FT', 'id' => 'r_office123']);
        $this->assertSame('Alice', $remark->refresh()->acknowledged_by_name);

        foreach (range(1, 65) as $i) {
            $this->send($this->technician, ['op' => 'tech_message', 'box' => 'BPE-0001-FT', 'text' => "Message {$i}"]);
        }

        $this->assertSame(60, $box->remarks()->count());
        $this->assertSame('Message 65', $box->remarks()->reorder('id', 'desc')->first()->text);
    }

    public function test_reports_control_points_and_folder_validations(): void
    {
        $box = $this->box();
        $this->send($this->technician, ['op' => 'box_cr', 'box' => 'BPE-0001-FT', 'items' => [['id' => 'a 01!', 'label' => 'Cap', 'qty' => 2], ['id' => '', 'label' => 'x', 'qty' => 1], ['id' => 'a02', 'label' => 'Bad', 'qty' => 0], 'junk', ['id' => 'a03', 'label' => 'Many', 'qty' => 5000]]]);
        $this->assertEquals([['id' => 'a01', 'label' => 'Cap', 'qty' => 2], ['id' => 'a03', 'label' => 'Many', 'qty' => 999]], $box->refresh()->report_items);

        $this->send($this->technician, ['op' => 'cp_add', 'name' => '  Extra   point ']);
        $point = $this->audit->controlPoints()->where('name', 'Extra point')->first();
        $this->assertTrue($point->is_custom);
        $this->assertSame('exists:409', $this->reason($this->technician, ['op' => 'cp_add', 'name' => 'extra POINT']));
        $this->send($this->technician, ['op' => 'cp_note', 'point' => $point->id, 'text' => 'Seen']);
        $this->send($this->technician, ['op' => 'cp_cr', 'point' => $point->id, 'items' => [['id' => 'm01', 'label' => 'Pole', 'qty' => 1]]]);
        $this->assertSame('Seen', $point->refresh()->note);
        $this->assertCount(1, $point->report_items);
        $this->assertSame('point_not_found:404', $this->reason($this->technician, ['op' => 'cp_note', 'point' => 99999, 'text' => 'x']));

        $stored = StoredFile::query()->create(['sha256' => 'f', 'path' => 'files/f.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 1]);
        AuditPhoto::query()->create(['audit_id' => $this->audit->id, 'audit_control_point_id' => $point->id, 'folder' => 'before', 'stored_file_id' => $stored->id, 'captured_at' => now(), 'received_at' => now()]);
        $this->assertSame('has_photos:409', $this->reason($this->manager, ['op' => 'cp_remove', 'point' => $point->id]));

        $this->assertSame('no_photos:422', $this->reason($this->manager, ['op' => 'folder_validate', 'box' => 'BPE-0001-FT', 'folder' => 'closed', 'valid' => true]));
        $this->assertSame('bad_folder:422', $this->reason($this->manager, ['op' => 'folder_validate', 'box' => 'BPE-0001-FT', 'folder' => 'nonsense', 'valid' => true]));
        AuditPhoto::query()->create(['audit_id' => $this->audit->id, 'audit_box_id' => $box->id, 'folder' => AuditFolder::Closed->value, 'stored_file_id' => $stored->id, 'captured_at' => now(), 'received_at' => now()]);
        $this->send($this->manager, ['op' => 'folder_validate', 'box' => 'BPE-0001-FT', 'folder' => 'closed', 'valid' => true]);
        $this->assertSame(1, $box->folderValidations()->sole()->photos_count);
        $this->send($this->manager, ['op' => 'folder_validate', 'box' => 'BPE-0001-FT', 'folder' => 'closed', 'valid' => false]);
        $this->assertSame(0, $box->folderValidations()->count());

        $this->send($this->manager, ['op' => 'cp_remove', 'point' => $this->audit->controlPoints()->where('kind', 'cleanup')->first()->id]);
        $this->assertSame(0, $this->audit->controlPoints()->where('kind', 'cleanup')->count());
    }

    public function test_a_manager_can_remove_and_correct_a_box(): void
    {
        $box = $this->box();

        $this->send($this->manager, ['op' => 'box_admin', 'box' => 'BPE-0001-FT', 'removed' => true, 'type' => 'Closure 12', 'address' => '1 Main street', 'kind' => 'pbo']);
        $box->refresh();
        $this->assertTrue($box->isRemoved());
        $this->assertSame('pbo', $box->kind->value);
        $this->assertSame('Closure 12', $box->type);

        $this->send($this->manager, ['op' => 'box_admin', 'box' => 'BPE-0001-FT', 'removed' => false]);
        $this->assertFalse($box->refresh()->isRemoved());
    }

    public function test_the_endpoint_applies_operations_only_for_people_working_on_the_audit(): void
    {
        $url = route('audits.operations', ['cabinet' => 'PM153']);
        $payload = fn (array $op) => ['op' => $op + ['operation_id' => 'o_'.bin2hex(random_bytes(4))]];

        $this->actingAs($this->technician)->postJson($url, $payload(['op' => 'box_add', 'name' => 'BPE-0001']))->assertNotFound()->assertJson(['ok' => false, 'error' => 'audit_not_found']);

        $site = WorkSite::factory()->create(['agency_id' => $this->agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM 153', 'lead_technician_id' => $this->technician->id]);
        $site->members()->attach($this->other->id, ['role' => 'partner']);

        $this->actingAs($this->technician)->postJson($url, $payload(['op' => 'box_add', 'name' => 'BPE-0001']))->assertOk()->assertJson(['ok' => true, 'duplicate' => false]);
        $this->actingAs($this->other)->postJson($url, $payload(['op' => 'box_add', 'name' => 'BPE-0002']))->assertOk();
        $this->actingAs($this->technician)->postJson($url, $payload(['op' => 'box_add', 'name' => 'BAD']))->assertStatus(422)->assertJson(['ok' => false, 'error' => 'name_format']);
        $this->actingAs($this->technician)->postJson($url, ['nothing' => true])->assertStatus(422)->assertJson(['error' => 'bad_request']);
        $this->actingAs($this->technician)->postJson(route('audits.operations', ['cabinet' => 'PM999']), $payload(['op' => 'box_add', 'name' => 'BPE-0001']))->assertNotFound();

        $stranger = User::factory()->withRole(Role::Technician)->create(['agency_id' => $this->agency->id]);
        $this->actingAs($stranger)->postJson($url, $payload(['op' => 'box_add', 'name' => 'BPE-0003']))->assertNotFound()->assertJson(['ok' => false, 'error' => 'audit_not_found']);
        $this->actingAs(User::factory()->withRole(Role::DesignOffice)->create(['agency_id' => $this->agency->id]))->postJson($url, $payload(['op' => 'box_add', 'name' => 'BPE-0004']))->assertOk();

        auth()->logout();
        $this->postJson($url, $payload(['op' => 'box_add', 'name' => 'BPE-0005']))->assertUnauthorized();
        $this->assertSame(3, $this->audit->boxes()->count());
    }

    public function test_only_managers_open_an_audit_through_the_endpoint_and_the_module_switch_applies(): void
    {
        $url = route('audits.operations', ['cabinet' => 'PM777']);
        $op = ['op' => ['op' => 'setup', 'mode' => 'optical', 'operation_id' => 'o_setup1']];

        $this->actingAs($this->technician)->postJson($url, $op)->assertNotFound();
        $this->actingAs($this->manager)->postJson($url, $op)->assertOk();
        $this->assertSame(AuditMode::Optical, Audit::query()->where('cabinet_key', 'PM777')->first()->mode);

        $modules = app(ModuleSettings::class);
        $modules->enabled = array_values(array_diff($modules->enabled, ['audits']));
        $modules->save();
        $this->actingAs($this->manager)->postJson($url, ['op' => ['op' => 'validate', 'operation_id' => 'o_v1']])->assertNotFound();
    }
}
