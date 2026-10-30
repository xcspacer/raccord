<?php

namespace Tests\Feature\Navigation;

use App\Enums\Feature;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\RoleFeatureOverride;
use App\Models\User;
use App\Support\EffectiveAgency;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_home_shows_the_user_and_role_in_the_user_language(): void
    {
        $user = User::factory()->withRole(Role::Supervisor)->create(['name' => 'Sam Dubois', 'locale' => 'fr']);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSeeText('Accueil')
            ->assertSeeText('Sam Dubois')
            ->assertSeeText('Superviseur')
            ->assertSeeText('Fonctions');
    }

    public function test_administrator_can_switch_agency(): void
    {
        [$north, $south] = Agency::factory()->count(2)->create();
        $admin = User::factory()->withRole(Role::Administrator)->create();

        $this->actingAs($admin)->from('/')->post(route('agency.switch', $south))->assertRedirect('/');

        $this->assertSame($south->id, session(EffectiveAgency::SESSION_KEY));
    }

    public function test_user_attached_to_one_agency_cannot_switch(): void
    {
        [$north, $south] = Agency::factory()->count(2)->create();
        $technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $north->id]);

        $this->actingAs($technician)->post(route('agency.switch', $south))->assertForbidden();
    }

    public function test_every_page_has_a_home_button_except_for_the_design_office(): void
    {
        Agency::factory()->create();
        $supervisor = User::factory()->withRole(Role::Supervisor)->create();
        $designOffice = User::factory()->withRole(Role::DesignOffice)->create();

        $this->actingAs($supervisor)->get(route('absences.index'))->assertSee('href="'.route('home').'"', false);
        $this->actingAs($designOffice)->get(route('audits.full'))->assertOk()->assertDontSee('href="'.route('home').'"', false);
    }

    public function test_the_design_office_lands_on_the_audits(): void
    {
        Agency::factory()->create();
        $designOffice = User::factory()->withRole(Role::DesignOffice)->create();

        $this->actingAs($designOffice)->get(route('home'))->assertRedirect(route('audits.full'));
        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get(route('home'))->assertOk();
    }

    public function test_the_design_office_without_audits_lands_on_its_first_tool(): void
    {
        Agency::factory()->create();
        $designOffice = User::factory()->withRole(Role::DesignOffice)->create();
        RoleFeatureOverride::query()->create(['feature' => Feature::Audits, 'role_id' => \Spatie\Permission\Models\Role::findByName(Role::DesignOffice->value)->id, 'is_enabled' => false]);

        $this->actingAs($designOffice)->get(route('home'))->assertRedirect(route('expense-reports'));
    }
}
