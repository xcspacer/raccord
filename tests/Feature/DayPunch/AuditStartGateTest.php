<?php

namespace Tests\Feature\DayPunch;

use App\Audit\OpenAudit;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Livewire\Audit\AuditIndex;
use App\Livewire\DayPunch\DeclareEndModal;
use App\Livewire\DayPunch\Gate;
use App\Models\Agency;
use App\Models\DayPunch;
use App\Models\User;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditStartGateTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-05 08:30', 'Europe/Paris'));
        $this->seed(RoleSeeder::class);
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id]);
        app(OpenAudit::class)->handle(User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id]), 'PM153');
        WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM153', 'lead_technician_id' => $this->technician->id]);

        $this->punch('2026-11-04');
        $this->punch('2026-11-05');
    }

    private function punch(string $date): DayPunch
    {
        return DayPunch::query()->create([
            'user_id' => $this->technician->id, 'date' => $date,
            'start_at' => CarbonImmutable::parse($date.' 07:45', 'Europe/Paris')->utc(), 'start_received_at' => now(),
        ]);
    }

    public function test_a_previous_day_left_open_never_blocks_the_app_when_it_opens(): void
    {
        $this->actingAs($this->technician)->get(route('home'))
            ->assertOk()
            ->assertSee(__('day_punch.end.declare_reminder', ['date' => '04 Nov']))
            ->assertDontSee('id="declare-title"', false)
            ->assertDontSee('overlay--gate', false);

        Livewire::actingAs($this->technician)->test(Gate::class)->assertDontSee(__('day_punch.gate.start'));
        Livewire::actingAs($this->technician)->test(DeclareEndModal::class)->assertSet('open', false);
        $this->actingAs($this->technician)->get(route('audits.full'))->assertOk();
    }

    public function test_starting_an_audit_asks_for_the_end_of_the_previous_day_then_carries_on(): void
    {
        Livewire::actingAs($this->technician)->test(AuditIndex::class, ['mode' => 'full'])
            ->call('open', 'PM153')
            ->assertDispatched('declare-end-open', date: '2026-11-04', continueTo: '/audits/cabinet/PM153')
            ->assertNoRedirect();

        Livewire::actingAs($this->technician)->test(DeclareEndModal::class)
            ->call('openFor', '2026-11-04', '/audits/cabinet/PM153')
            ->call('close')
            ->assertSet('open', false)
            ->assertNoRedirect();

        Livewire::actingAs($this->technician)->test(DeclareEndModal::class)
            ->call('openFor', '2026-11-04', '/audits/cabinet/PM153')
            ->set('time', '17:30')
            ->call('declare')
            ->assertHasNoErrors()
            ->assertRedirect('/audits/cabinet/PM153');

        Livewire::actingAs($this->technician)->test(AuditIndex::class, ['mode' => 'full'])
            ->call('open', 'PM153')
            ->assertNotDispatched('declare-end-open')
            ->assertRedirect(route('audits.show', ['cabinet' => 'PM153']));
    }

    public function test_the_audit_page_itself_refuses_to_start_while_the_previous_day_is_open(): void
    {
        $this->actingAs($this->technician)->get(route('audits.show', ['cabinet' => 'PM153']))->assertRedirect(route('audits.full'));

        DayPunch::query()->whereDate('date', '2026-11-04')->update(['end_at' => CarbonImmutable::parse('2026-11-04 17:30', 'Europe/Paris')->utc()]);

        $this->actingAs($this->technician)->get(route('audits.show', ['cabinet' => 'PM153']))->assertOk();
    }

    public function test_the_declaration_only_carries_on_to_a_page_of_the_application(): void
    {
        foreach (['https://example.test/audits', '//example.test/audits', '/\\example.test'] as $target) {
            Livewire::actingAs($this->technician)->test(DeclareEndModal::class)
                ->call('openFor', '2026-11-04', $target)
                ->assertSet('continueTo', null)
                ->set('time', '17:30')
                ->call('declare')
                ->assertNoRedirect();

            DayPunch::query()->whereDate('date', '2026-11-04')->update(['end_at' => null, 'end_declared' => false]);
        }
    }

    public function test_managers_open_audits_without_any_day_punch_question(): void
    {
        $manager = User::factory()->withRole(Role::Supervisor)->create();

        Livewire::actingAs($manager)->test(AuditIndex::class, ['mode' => 'full'])
            ->call('open', 'PM153')
            ->assertNotDispatched('declare-end-open')
            ->assertRedirect(route('audits.show', ['cabinet' => 'PM153']));
    }
}
