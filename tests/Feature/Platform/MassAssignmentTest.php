<?php

namespace Tests\Feature\Platform;

use App\Enums\Role;
use App\Livewire\Profile\ProfileModal;
use App\Models\BillingExpense;
use App\Models\ClientAccount;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

/**
 * Models only take the columns they list as fillable, and outside production anything else sent to them fails
 * loudly instead of being dropped (VAL-02, VAL-03).
 */
class MassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_model_lists_its_fillable_columns(): void
    {
        $models = collect(File::files(app_path('Models')))
            ->map(fn ($file) => 'App\\Models\\'.$file->getFilenameWithoutExtension())
            ->filter(fn (string $class) => is_subclass_of($class, Model::class));

        $this->assertNotEmpty($models);

        foreach ($models as $class) {
            /** @var Model $model */
            $model = new $class;

            $this->assertNotSame([], $model->getFillable(), "{$class} lists no fillable column.");
            $this->assertNotSame([], $model->getGuarded(), "{$class} accepts every column.");
        }
    }

    public function test_models_are_strict_outside_production(): void
    {
        $this->assertTrue(Model::preventsLazyLoading());
        $this->assertTrue(Model::preventsSilentlyDiscardingAttributes());
        $this->assertTrue(Model::preventsAccessingMissingAttributes());
    }

    public function test_a_person_cannot_be_given_protected_columns_through_mass_assignment(): void
    {
        foreach (['role', 'remember_token', 'legacy_password_hash', 'legacy_password_expires_at', 'anonymised_at', 'deleted_at', 'id'] as $column) {
            $this->assertMassAssignmentRefused(new User, $column);
        }
    }

    public function test_other_sensitive_models_refuse_columns_the_code_never_mass_assigns(): void
    {
        $this->assertMassAssignmentRefused(new ClientAccount, 'remember_token');
        $this->assertMassAssignmentRefused(new BillingExpense, 'deleted_at');
        $this->assertMassAssignmentRefused(new InventoryItem, 'serial_normalized');
        $this->assertMassAssignmentRefused(new InventoryItem, 'split_from_id');
    }

    public function test_the_profile_form_cannot_be_used_to_change_the_role_or_the_agency(): void
    {
        $this->seed(RoleSeeder::class);
        $technician = User::factory()->withRole(Role::Technician)->create();

        foreach (['role', 'agency_id', 'all_agencies', 'is_plannable'] as $property) {
            try {
                Livewire::actingAs($technician)->test(ProfileModal::class)->set($property, 'administrator');
                $this->fail("The profile form accepted « {$property} ».");
            } catch (Throwable $exception) {
                $this->assertStringContainsString($property, $exception->getMessage());
            }
        }

        $technician->refresh();
        $this->assertTrue($technician->hasRole(Role::Technician->value));
        $this->assertFalse($technician->hasRole(Role::Administrator->value));
        $this->assertNull($technician->agency_id);
    }

    private function assertMassAssignmentRefused(Model $model, string $column): void
    {
        try {
            $model->fill([$column => 'x']);
        } catch (MassAssignmentException) {
            $this->assertFalse($model->isFillable($column));

            return;
        }

        $this->fail($model::class." accepted « {$column} » by mass assignment.");
    }
}
