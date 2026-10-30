<?php

namespace Tests\Feature\DayPunch;

use App\Enums\AbsenceType;
use App\Enums\Role;
use App\Livewire\DayPunch\DeclareEndModal;
use App\Livewire\DayPunch\EndButton;
use App\Livewire\DayPunch\Gate;
use App\Models\Absence;
use App\Models\DayPunch;
use App\Models\DayPunchEvent;
use App\Models\User;
use App\Settings\ModuleSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class DayPunchTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00', 'Europe/Paris'));
        $this->seed(RoleSeeder::class);
        $this->technician = User::factory()->withRole(Role::Technician)->create();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function punch(string $operation, array $payload = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->technician)->postJson('/day-punch/'.$operation, $payload);
    }

    public function test_start_is_recorded_with_the_server_clock_and_the_position(): void
    {
        $this->punch('start', ['lat' => 43.9298, 'lng' => 2.148, 'accuracy' => 12.4, 'client_at' => '2020-01-01T00:00:00Z'])
            ->assertOk()->assertJson(['ok' => true, 'duplicate' => false, 'date' => '2026-11-04', 'start' => '08:00']);

        $punch = DayPunch::query()->sole();
        $this->assertSame('43.929800', $punch->start_latitude);
        $this->assertSame(12, $punch->start_accuracy);
        $this->assertFalse($punch->start_offline);
        $this->assertFalse($punch->start_geo_missing);
    }

    public function test_starting_twice_is_harmless(): void
    {
        $this->punch('start');
        $this->travel(30)->minutes();
        $this->punch('start')->assertOk()->assertJson(['duplicate' => true, 'start' => '08:00']);

        $this->assertSame(1, DayPunch::query()->count());
        $this->assertSame(2, DayPunchEvent::query()->count());
        $this->assertTrue(DayPunchEvent::query()->latest('id')->first()?->duplicate);
    }

    public function test_missing_position_is_recorded_with_its_reason(): void
    {
        $this->punch('start', ['reason' => 'denied'])->assertOk();

        $punch = DayPunch::query()->sole();
        $this->assertTrue($punch->start_geo_missing);
        $this->assertSame('denied', $punch->start_geo_reason);
        $this->assertNull($punch->start_latitude);
    }

    public function test_the_phone_clock_is_trusted_only_for_requests_that_waited(): void
    {
        $this->punch('start', ['client_at' => '2026-11-04T05:30:00Z', 'queued' => false]);
        $this->assertSame('08:00', DayPunch::query()->sole()->startLocal());

        DayPunch::query()->delete();
        $this->punch('start', ['client_at' => CarbonImmutable::now()->subHours(2)->toIso8601String(), 'queued' => true]);
        $this->assertSame('06:00', DayPunch::query()->sole()->startLocal());
        $this->assertTrue(DayPunch::query()->sole()->start_offline);

        DayPunch::query()->delete();
        $this->punch('start', ['client_at' => CarbonImmutable::now()->subHours(40)->toIso8601String(), 'queued' => true]);
        $this->assertSame('08:00', DayPunch::query()->sole()->startLocal());

        DayPunch::query()->delete();
        $this->punch('start', ['client_at' => CarbonImmutable::now()->addHour()->toIso8601String(), 'queued' => true]);
        $this->assertSame('08:00', DayPunch::query()->sole()->startLocal());
    }

    public function test_end_closes_the_day_and_a_second_end_is_a_duplicate(): void
    {
        $this->punch('start');
        $this->travel(9)->hours();

        $this->punch('end', ['lat' => 43.9, 'lng' => 2.1])->assertOk()->assertJson(['end' => '17:00', 'duplicate' => false]);
        $this->punch('end')->assertOk()->assertJson(['duplicate' => true]);
        $this->assertSame('17:00', DayPunch::query()->sole()->endLocal());
    }

    public function test_end_without_an_open_day_or_after_a_forgotten_day_is_refused(): void
    {
        $this->punch('end')->assertStatus(409)->assertJson(['ok' => false, 'error' => 'no_open_day']);

        $this->punch('start');
        $this->travelTo(CarbonImmutable::parse('2026-11-05 08:30', 'Europe/Paris'));
        $this->punch('end')->assertStatus(409)->assertJson(['error' => 'previous_day_open']);
    }

    public function test_a_forgotten_day_can_be_declared_with_limits(): void
    {
        $this->punch('start');
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00', 'Europe/Paris'));

        $this->punch('declare-end', ['date' => '2026-11-04', 'time' => '07:00'])->assertStatus(422)->assertJson(['error' => 'end_before_start']);
        $this->punch('declare-end', ['date' => '2026-11-04', 'time' => '18:30'])->assertOk()->assertJson(['end' => '18:30']);

        $punch = DayPunch::query()->sole();
        $this->assertTrue($punch->end_declared);
        $this->assertTrue($punch->end_geo_missing);
        $this->punch('declare-end', ['date' => '2026-11-04', 'time' => '19:00'])->assertOk()->assertJson(['duplicate' => true]);
    }

    public function test_only_technicians_can_punch_and_input_is_validated(): void
    {
        $this->punch('start', [], User::factory()->withRole(Role::Supervisor)->create())->assertForbidden();
        $this->punch('start', ['lat' => 120])->assertUnprocessable();
        $this->punch('declare-end', ['date' => 'x'])->assertUnprocessable();
        $this->actingAs($this->technician)->postJson('/day-punch/explode')->assertNotFound();
    }

    public function test_guests_cannot_punch(): void
    {
        $this->postJson('/day-punch/start')->assertUnauthorized();
    }

    public function test_the_module_switch_blocks_the_endpoint(): void
    {
        $settings = app(ModuleSettings::class);
        $settings->enabled = array_values(array_diff($settings->enabled, ['daily_clock_in']));
        $settings->save();

        $this->punch('start')->assertNotFound();
    }

    public function test_the_gate_appears_on_working_days_without_a_start_and_closes_after_it(): void
    {
        Livewire::actingAs($this->technician)->test(Gate::class)->assertSee(__('day_punch.gate.start'));

        $this->punch('start');
        Livewire::actingAs($this->technician)->test(Gate::class)->assertDontSee(__('day_punch.gate.start'));
    }

    public function test_the_gate_tells_who_sees_the_position_and_how_long_it_is_kept(): void
    {
        Livewire::actingAs($this->technician)->test(Gate::class)
            ->assertSee(__('day_punch.gate.hint'))
            ->assertSee(__('day_punch.gate.privacy', ['months' => 12]));
    }

    public function test_the_gate_skips_sundays_absences_and_other_roles(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00', 'Europe/Paris'));
        Livewire::actingAs($this->technician)->test(Gate::class)->assertDontSee(__('day_punch.gate.start'));

        $this->travelTo(CarbonImmutable::parse('2026-11-07 08:00', 'Europe/Paris'));
        Livewire::actingAs($this->technician)->test(Gate::class)->assertSee(__('day_punch.gate.start'));

        Absence::factory()->create(['user_id' => $this->technician->id, 'type' => AbsenceType::Sick, 'starts_on' => '2026-11-07', 'ends_on' => '2026-11-07']);
        Livewire::actingAs($this->technician)->test(Gate::class)->assertDontSee(__('day_punch.gate.start'));

        Livewire::actingAs(User::factory()->withRole(Role::Supervisor)->create())->test(Gate::class)->assertDontSee(__('day_punch.gate.start'));
    }

    public function test_the_header_shows_the_end_button_while_the_day_runs(): void
    {
        Livewire::actingAs($this->technician)->test(EndButton::class)->assertDontSee(__('day_punch.end.button'));

        $this->punch('start');
        Livewire::actingAs($this->technician)->test(EndButton::class)->assertSee(__('day_punch.end.button'));

        $this->actingAs($this->technician)->get(route('home'))->assertSee('data-punch-end', false);
    }

    public function test_an_earlier_open_day_can_be_declared_from_the_modal(): void
    {
        $this->punch('start');
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00', 'Europe/Paris'));

        Livewire::actingAs($this->technician)->test(EndButton::class)->assertSee(__('day_punch.end.declare_reminder', ['date' => '04 Nov']));

        Livewire::actingAs($this->technician)->test(DeclareEndModal::class)
            ->call('openFor', '2026-11-04')->assertSet('open', true)
            ->set('time', '07:00')->call('declare')->assertHasErrors('time')
            ->set('time', '17:45')->call('declare')->assertHasNoErrors()->assertDispatched('day-punch-changed');

        $this->assertSame('17:45', DayPunch::query()->sole()->endLocal());
    }

    public function test_nobody_can_declare_someone_elses_day(): void
    {
        $this->punch('start');
        $other = User::factory()->withRole(Role::Technician)->create();

        Livewire::actingAs($other)->test(DeclareEndModal::class)->call('openFor', '2026-11-04')->assertNotFound();
    }
}
