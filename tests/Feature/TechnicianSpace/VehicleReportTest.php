<?php

namespace Tests\Feature\TechnicianSpace;

use App\Enums\Role;
use App\Models\User;
use App\Models\VehicleDamage;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VehicleReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RoleSeeder::class);
    }

    public function test_the_technician_downloads_the_pdf_of_the_vehicle_condition(): void
    {
        $technician = User::factory()->withRole(Role::Technician)->create(['name' => 'Jane Doe']);
        VehicleDamage::query()->create(['user_id' => $technician->id, 'type' => 'scratch', 'details' => 'Rear door', 'occurred_on' => now()->toDateString()]);

        $response = $this->actingAs($technician)->get(route('vehicle-report'));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Jane Doe', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_guests_and_users_without_the_feature_are_refused(): void
    {
        $this->get(route('vehicle-report'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get(route('vehicle-report'))->assertForbidden();
    }
}
