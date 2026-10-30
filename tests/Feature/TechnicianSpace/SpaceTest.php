<?php

namespace Tests\Feature\TechnicianSpace;

use App\Enums\AbsenceType;
use App\Enums\Role;
use App\Livewire\TechnicianSpace\Space;
use App\Models\Absence;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SpaceTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Europe/Paris'));
        $this->seed(RoleSeeder::class);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['name' => 'Léa Martin']);
    }

    public function test_only_technicians_open_the_space(): void
    {
        $this->actingAs($this->technician)->get(route('technician-space'))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get(route('technician-space'))->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::Administrator)->create())->get(route('technician-space'))->assertForbidden();
    }

    public function test_timesheet_counts_worked_and_absent_days_and_never_mixes_people(): void
    {
        Task::factory()->create(['user_id' => $this->technician->id, 'date' => '2026-11-02', 'label' => 'SWAP', 'detail' => 'Dijon']);
        Task::factory()->create(['user_id' => $this->technician->id, 'date' => '2026-11-03', 'label' => 'NRO']);
        Task::factory()->create(['user_id' => $this->technician->id, 'date' => '2026-11-04', 'label' => 'MOVED']);
        Absence::factory()->create(['user_id' => $this->technician->id, 'type' => AbsenceType::Leave, 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-05']);
        Absence::factory()->create(['user_id' => $this->technician->id, 'type' => AbsenceType::Sick, 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-09']);
        Task::factory()->create(['date' => '2026-11-02', 'label' => 'OTHER-PERSON']);

        Livewire::actingAs($this->technician)->test(Space::class)->call('setTab', 'timesheet')
            ->assertSee('SWAP')->assertDontSee('OTHER-PERSON')
            ->assertSee(__('technician_space.timesheet.summary', ['month' => 'November 2026', 'worked' => 2, 'absent' => 0, 'leave' => 2, 'sick' => 1]))
            ->set('month', '2026-10')->assertSee(__('technician_space.timesheet.empty'));
    }

    public function test_vehicle_is_saved_and_updated(): void
    {
        $component = Livewire::actingAs($this->technician)->test(Space::class)->call('setTab', 'vehicle')
            ->set('vehicleModel', 'Clio')->set('vehiclePlate', 'ab-123-cd')->call('saveVehicle')->assertHasNoErrors()->assertDispatched('toast');

        $this->assertSame('Clio', $this->technician->fresh()?->vehicle?->model);

        $component->set('vehicleModel', 'Berlingo')->call('saveVehicle');
        $this->assertSame(1, $this->technician->vehicle()->count());
        $this->assertSame('Berlingo', $this->technician->fresh()?->vehicle?->model);

        $component->set('vehicleModel', str_repeat('x', 100))->call('saveVehicle')->assertHasErrors('vehicleModel');
    }

    public function test_daily_return_email_follows_the_form_and_the_legacy_format(): void
    {
        $component = Livewire::actingAs($this->technician)->test(Space::class)->call('setTab', 'daily-return')
            ->set('returnDate', '2026-11-04')->set('team', 'Léa / Hugo')
            ->set('boxes.0.works', "Fusion des fibres\n\nContrôle")
            ->set('boxes.0.cables.0.description', '288 FO M12')->set('boxes.0.cables.0.tubes', '12')
            ->set('boxes.0.reference', 'BPE-1');

        $text = $component->get('emailText');

        $this->assertStringContainsString("04/11/26\n\nTeam: Léa / Hugo", $text);
        $this->assertStringContainsString("• Fusion des fibres\n• Contrôle\n• Preparation of a cable 288 FO M12", $text);
        $this->assertStringContainsString('Total number of welds made: 12', $text);
        $this->assertStringNotContainsString('BPE-1', $text);

        $component->set('boxes.0.welds', '20');
        $this->assertStringContainsString('made: 20', $component->get('emailText'));
    }

    public function test_several_boxes_add_a_day_total_and_manual_edits_are_kept_until_regenerated(): void
    {
        $component = Livewire::actingAs($this->technician)->test(Space::class)->call('setTab', 'daily-return')
            ->set('boxes.0.welds', '10')->call('addBox')->set('boxes.1.welds', '5');

        $this->assertStringContainsString(str_repeat('─', 32), $component->get('emailText'));
        $this->assertStringContainsString('(day): 15', $component->get('emailText'));

        $component->set('emailText', 'My own text')->set('team', 'Someone')->assertSet('emailText', 'My own text');
        $component->call('regenerate');
        $this->assertStringContainsString('Team: Someone', $component->get('emailText'));

        $component->call('removeBox', 1)->call('removeBox', 0);
        $this->assertCount(1, $component->get('boxes'));
    }

    public function test_the_default_team_is_the_work_site_crew_of_today(): void
    {
        $site = WorkSite::factory()->create(['starts_on' => '2026-11-04', 'ends_on' => '2026-11-04']);
        $partner = User::factory()->create(['name' => 'Hugo Petit']);
        $site->members()->sync([$partner->id => ['role' => 'partner'], $this->technician->id => ['role' => 'lead']]);
        Task::factory()->create(['user_id' => $this->technician->id, 'date' => '2026-11-04', 'work_site_id' => $site->id]);

        Livewire::actingAs($this->technician)->test(Space::class)->assertSet('team', 'Léa Martin / Hugo Petit');
    }
}
