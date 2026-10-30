<?php

namespace Tests\Feature\Admin;

use App\Enums\HolidayRule;
use App\Enums\Role;
use App\Livewire\Admin\HolidayIndex;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Support\PublicHolidays;
use Carbon\CarbonImmutable;
use Database\Seeders\PublicHolidaySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HolidayManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->withRole(Role::Administrator)->create();
    }

    public function test_only_administrators_open_the_page(): void
    {
        $this->actingAs($this->admin)->get(route('holidays.index'))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get(route('holidays.index'))->assertForbidden();
        Livewire::actingAs(User::factory()->withRole(Role::Supervisor)->create())->test(HolidayIndex::class)->assertForbidden();
    }

    public function test_starting_list_is_seeded_once_and_computes_movable_feasts(): void
    {
        $this->seed(PublicHolidaySeeder::class);
        $this->seed(PublicHolidaySeeder::class);

        $this->assertSame(11, PublicHoliday::query()->count());

        $holidays = app(PublicHolidays::class);
        $this->assertTrue($holidays->isHoliday(CarbonImmutable::parse('2026-04-06')));
        $this->assertTrue($holidays->isHoliday(CarbonImmutable::parse('2026-05-14')));
        $this->assertTrue($holidays->isHoliday(CarbonImmutable::parse('2027-03-29')));
        $this->assertFalse($holidays->isWorkingDay(CarbonImmutable::parse('2026-12-25')));
        $this->assertTrue($holidays->isWorkingDay(CarbonImmutable::parse('2026-12-24')));
    }

    public function test_administrator_adds_edits_and_deletes_holidays(): void
    {
        $component = Livewire::actingAs($this->admin)->test(HolidayIndex::class)
            ->set('name', 'Founders Day')->set('rule', HolidayRule::FixedDate->value)->set('day', '10')->set('month', '6')
            ->call('save')
            ->assertHasNoErrors();

        $holiday = PublicHoliday::query()->sole();
        $this->assertSame('2026-06-10', $holiday->occurrenceIn(2026)?->toDateString());

        $component->call('edit', $holiday->id)
            ->set('rule', HolidayRule::SingleDate->value)->set('date', '2026-09-01')
            ->call('save')
            ->assertHasNoErrors();

        $holiday->refresh();
        $this->assertSame(HolidayRule::SingleDate, $holiday->rule);
        $this->assertNull($holiday->month);
        $this->assertNull($holiday->occurrenceIn(2027));

        $component->call('delete', $holiday->id);
        $this->assertModelMissing($holiday);
    }

    public function test_invalid_holidays_are_refused(): void
    {
        Livewire::actingAs($this->admin)->test(HolidayIndex::class)
            ->set('name', 'Bad')->set('day', '31')->set('month', '2')
            ->call('save')->assertHasErrors('day')
            ->set('name', '')->call('save')->assertHasErrors('name')
            ->set('name', 'X')->set('rule', 'lunar')->call('save')->assertHasErrors('rule');

        $this->assertDatabaseCount('public_holidays', 0);
    }
}
