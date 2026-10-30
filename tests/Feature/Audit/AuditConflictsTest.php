<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditOperationException;
use App\Audit\AuditOperations;
use App\Audit\OpenAudit;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Http\Middleware\EnsureCurrentSyncProtocol;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\User;
use App\Models\WorkSite;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditConflictsTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    private User $partner;

    private Audit $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader(EnsureCurrentSyncProtocol::HEADER, (string) EnsureCurrentSyncProtocol::VERSION);

        config(['audit.box_suffixes' => ['FT']]);
        $this->seed(RoleSeeder::class);
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id]);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id, 'name' => 'Alice']);
        $this->partner = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id, 'name' => 'Bruno']);
        $this->audit = app(OpenAudit::class)->handle($manager, 'PM153');
        $site = WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM153', 'lead_technician_id' => $this->technician->id]);
        $site->members()->attach($this->partner->id, ['role' => 'partner']);
        $this->send($this->technician, ['op' => 'box_add', 'name' => 'BPE-0001-FT']);
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array{duplicate: bool, revision: int}
     */
    private function send(User $user, array $op): array
    {
        $this->actingAs($user);

        return app(AuditOperations::class)->apply($user, $this->audit->refresh(), 'o_'.bin2hex(random_bytes(5)), $op);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function refusal(User $user, array $op): ?AuditOperationException
    {
        try {
            $this->send($user, $op);
        } catch (AuditOperationException $exception) {
            return $exception;
        }

        return null;
    }

    private function box(): AuditBox
    {
        return $this->audit->boxes()->where('name', 'BPE-0001-FT')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function note(string $text, int $base, string $device, array $extra = []): array
    {
        return ['op' => 'box_note', 'box' => 'BPE-0001-FT', 'key' => 'route', 'text' => $text, 'base_revision' => $base, 'device' => $device] + $extra;
    }

    public function test_a_field_changed_on_another_device_is_never_overwritten_in_silence(): void
    {
        $base = $this->audit->refresh()->revision;
        $this->send($this->partner, $this->note('Bruno\'s route', $base, 'phone_b'));

        $refusal = $this->refusal($this->technician, $this->note('Alice\'s route', $base, 'phone_a'));

        $this->assertSame('conflict', $refusal?->reason);
        $this->assertSame(409, $refusal->status);
        $this->assertSame('box:BPE-0001-FT:note_route', $refusal->conflict['field']);
        $this->assertSame('Bruno', $refusal->conflict['by']);
        $this->assertSame('Bruno\'s route', $this->box()->note_route);
    }

    public function test_the_same_person_on_two_phones_is_asked_too(): void
    {
        $base = $this->audit->refresh()->revision;
        $this->send($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001-FT', 'status' => 'partial', 'base_revision' => $base, 'device' => 'tablet']);

        $this->assertSame('conflict', $this->refusal($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001-FT', 'status' => 'not_installed', 'base_revision' => $base, 'device' => 'phone'])?->reason);
        $this->assertSame('partial', $this->box()->status->value);
    }

    public function test_keeping_mine_overwrites_and_keeping_theirs_changes_nothing(): void
    {
        $base = $this->audit->refresh()->revision;
        $this->send($this->partner, $this->note('Bruno\'s route', $base, 'phone_b'));

        $this->send($this->technician, $this->note('Alice\'s route', $base, 'phone_a', ['keep_mine' => true]));

        $this->assertSame('Alice\'s route', $this->box()->note_route);
        $this->assertSame('conflict', $this->refusal($this->partner, $this->note('Bruno again', $base + 1, 'phone_b'))?->reason);
    }

    public function test_no_conflict_for_the_device_own_edits_an_identical_value_or_a_fresh_base(): void
    {
        $base = $this->audit->refresh()->revision;
        $this->send($this->technician, $this->note('First', $base, 'phone_a'));
        $this->assertNull($this->refusal($this->technician, $this->note('Second', $base, 'phone_a')));

        $this->send($this->partner, $this->note('Same', $this->audit->refresh()->revision, 'phone_b'));
        $this->assertNull($this->refusal($this->technician, $this->note('Same', $base, 'phone_a')));
        $this->assertNull($this->refusal($this->technician, $this->note('Fresh', $this->audit->refresh()->revision, 'phone_a')));
        $this->assertNull($this->refusal($this->partner, ['op' => 'box_note', 'box' => 'BPE-0001-FT', 'key' => 'before', 'text' => 'Other field', 'base_revision' => $base, 'device' => 'phone_b']));

        $this->assertSame('Fresh', $this->box()->note_route);
    }

    public function test_the_endpoint_returns_the_conflict_to_the_phone(): void
    {
        $base = $this->audit->refresh()->revision;
        $this->send($this->partner, ['op' => 'box_set', 'box' => 'BPE-0001-FT', 'field' => 'support', 'value' => 'pole', 'base_revision' => $base, 'device' => 'phone_b']);

        $this->actingAs($this->technician)->postJson(route('audits.operations', ['cabinet' => 'PM153']), ['op' => [
            'op' => 'box_set', 'box' => 'BPE-0001-FT', 'field' => 'support', 'value' => 'chamber', 'base_revision' => $base, 'device' => 'phone_a', 'operation_id' => 'o_conflict1',
        ]])->assertStatus(409)->assertJson(['ok' => false, 'error' => 'conflict', 'conflict' => ['field' => 'box:BPE-0001-FT:support', 'by' => 'Bruno']]);

        $this->assertSame('pole', $this->box()->support->value);
    }
}
