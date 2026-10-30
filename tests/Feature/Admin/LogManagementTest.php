<?php

namespace Tests\Feature\Admin;

use App\Actions\Logs\RestoreChanges;
use App\Actions\Users\ChangeUserRole;
use App\Enums\Role;
use App\Livewire\Admin\LogIndex;
use App\Logs\ActivityBatch;
use App\Logs\SecurityLog;
use App\Models\Agency;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class LogManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->withRole(Role::Administrator)->create(['name' => 'Alex Admin']);
    }

    public function test_only_administrators_open_the_page(): void
    {
        $this->actingAs($this->admin)->get(route('logs.index'))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get(route('logs.index'))->assertForbidden();
        Livewire::actingAs(User::factory()->withRole(Role::Supervisor)->create())->test(LogIndex::class)->assertForbidden();
    }

    public function test_secrets_are_never_recorded(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);
        $user->update(['password' => 'other-password', 'phone' => '0102030405']);

        Activity::query()->where('log_name', 'users')->get()->each(function (Activity $activity): void {
            foreach ($activity->attribute_changes ?? [] as $values) {
                $this->assertArrayNotHasKey('password', $values);
                $this->assertArrayNotHasKey('legacy_password_hash', $values);
                $this->assertArrayNotHasKey('remember_token', $values);
            }
        });
    }

    public function test_changed_task_is_restored_to_its_previous_state(): void
    {
        $this->actingAs($this->admin);
        $task = Task::factory()->create(['label' => 'Raccordement']);
        $this->app->forgetScopedInstances();
        $task->update(['label' => 'Changed']);

        $change = Activity::query()->where('event', 'updated')->sole();

        Livewire::test(LogIndex::class)
            ->assertSee('Raccordement')
            ->call('restore', [$change->id])
            ->assertDispatched('toast');

        $this->assertSame('Raccordement', $task->fresh()?->label);
        $this->assertSame($this->admin->id, Activity::query()->latest('id')->first()?->causer_id);
    }

    public function test_deleted_records_come_back(): void
    {
        $this->actingAs($this->admin);
        $task = Task::factory()->create();
        $site = WorkSite::factory()->create(['city' => 'Villefranche']);
        $task->delete();
        $site->delete();

        $ids = Activity::query()->where('event', 'deleted')->pluck('id')->all();

        Livewire::test(LogIndex::class)->call('toggleMany', $ids, true)->call('restore');

        $this->assertModelExists($task);
        $this->assertFalse($site->fresh()?->trashed());
    }

    public function test_restoring_a_user_keeps_the_current_password(): void
    {
        $this->actingAs($this->admin);
        $user = User::factory()->create(['name' => 'Old Name', 'password' => 'current-password']);
        $this->app->forgetScopedInstances();
        $user->update(['name' => 'New Name']);

        Livewire::test(LogIndex::class)->call('restore', [Activity::query()->where('event', 'updated')->sole()->id]);

        $user->refresh();
        $this->assertSame('Old Name', $user->name);
        $this->assertTrue(Hash::check('current-password', (string) $user->password));
    }

    public function test_added_records_cannot_be_restored(): void
    {
        $this->actingAs($this->admin);
        $task = Task::factory()->create(['label' => 'Created']);
        $task->forceFill(['label' => 'Still here'])->saveQuietly();

        Livewire::test(LogIndex::class)->call('restore', [Activity::query()->where('event', 'created')->where('log_name', 'tasks')->sole()->id]);

        $this->assertSame('Still here', $task->fresh()?->label);
    }

    public function test_one_request_is_one_event(): void
    {
        $this->actingAs($this->admin);
        Task::factory()->count(2)->create();
        $this->app->forgetScopedInstances();
        Task::factory()->create();

        $batches = Activity::query()->where('log_name', 'tasks')->get()->map(fn (Activity $activity) => $activity->getProperty('batch'))->unique();
        $this->assertCount(2, $batches);
        $this->assertNotNull(app(ActivityBatch::class)->id());
    }

    public function test_a_role_change_is_listed_with_its_author_and_cannot_be_restored(): void
    {
        $handler = $this->securityLog();
        $this->actingAs($this->admin);
        $user = User::factory()->withRole(Role::Technician)->create(['name' => 'Tina Tech']);
        $this->app->forgetScopedInstances();

        app(ChangeUserRole::class)->handle($this->admin, $user, Role::Supervisor);

        $this->assertTrue($user->fresh()->hasRole(Role::Supervisor->value));
        $change = Activity::query()->where('event', ChangeUserRole::EVENT)->sole();
        $this->assertSame('users', $change->log_name);
        $this->assertSame($this->admin->id, $change->causer_id);
        $this->assertEquals(['attributes' => ['role' => 'supervisor'], 'old' => ['role' => 'technician']], $change->attribute_changes?->toArray());
        $this->assertTrue($handler->hasRecord(SecurityLog::ROLE_CHANGED, Level::Warning));

        Livewire::test(LogIndex::class)
            ->assertSee(__('logs.ops.role_changed'))
            ->assertSee('Tina Tech ('.__('roles.technician').' → '.__('roles.supervisor').')');

        $this->assertSame(['restored' => 0, 'failed' => 0, 'conflicts' => 0], app(RestoreChanges::class)->handle([$change->id]));
        $this->assertTrue($user->fresh()->hasRole(Role::Supervisor->value));
    }

    public function test_choosing_the_same_role_records_nothing(): void
    {
        $user = User::factory()->withRole(Role::Technician)->create();

        app(ChangeUserRole::class)->handle($this->admin, $user, Role::Technician);

        $this->assertSame(0, Activity::query()->where('event', ChangeUserRole::EVENT)->count());
    }

    public function test_restoring_a_user_never_puts_back_their_agency(): void
    {
        $this->actingAs($this->admin);
        [$north, $south] = Agency::factory()->count(2)->create();
        $user = User::factory()->create(['name' => 'Old Name', 'agency_id' => $north->id]);
        $this->app->forgetScopedInstances();
        $user->update(['name' => 'New Name', 'agency_id' => $south->id]);

        Livewire::test(LogIndex::class)->call('restore', [Activity::query()->where('event', 'updated')->where('subject_id', $user->id)->sole()->id]);

        $user->refresh();
        $this->assertSame('Old Name', $user->name);
        $this->assertSame($south->id, $user->agency_id);
    }

    public function test_a_record_changed_since_the_entry_is_not_overwritten(): void
    {
        $this->actingAs($this->admin);
        $task = Task::factory()->create(['label' => 'First']);
        $this->app->forgetScopedInstances();
        $task->update(['label' => 'Second']);
        $this->app->forgetScopedInstances();
        $task->update(['label' => 'Third']);

        [$older, $newer] = Activity::query()->where('event', 'updated')->orderBy('id')->pluck('id')->all();

        Livewire::test(LogIndex::class)
            ->call('restore', [$older])
            ->assertDispatched('toast', message: trans_choice('logs.restored', 0, ['count' => 0]).' '.trans_choice('logs.restore_conflicts', 1, ['count' => 1]));
        $this->assertSame('Third', $task->refresh()->label);

        $this->assertSame(['restored' => 2, 'failed' => 0, 'conflicts' => 0], app(RestoreChanges::class)->handle([$older, $newer]));
        $this->assertSame('Second', $task->refresh()->label);

        $this->assertSame(['restored' => 0, 'failed' => 0, 'conflicts' => 1], app(RestoreChanges::class)->handle([$newer]), 'The restore itself is a later change.');
    }

    public function test_a_deleted_record_brought_back_since_is_not_restored_again(): void
    {
        $this->actingAs($this->admin);
        $site = WorkSite::factory()->create(['city' => 'Villefranche']);
        $this->app->forgetScopedInstances();
        $site->delete();
        $this->app->forgetScopedInstances();
        $site->restore();
        $this->app->forgetScopedInstances();
        $site->update(['city' => 'Elsewhere']);

        $deleted = Activity::query()->where('event', 'deleted')->sole();

        $this->assertSame(1, app(RestoreChanges::class)->handle([$deleted->id])['conflicts']);
        $this->assertSame('Elsewhere', $site->fresh()?->city);
    }

    public function test_imported_history_is_read_only(): void
    {
        $this->actingAs($this->admin);
        $task = Task::factory()->create(['label' => 'Current']);
        $entry = $this->importedEntry('tasks', 'updated', $task->getMorphClass(), $task->id, ['old' => ['label' => 'Before'], 'attributes' => ['label' => 'Current']]);

        Livewire::test(LogIndex::class)
            ->assertSee('Before')
            ->assertViewHas('restorableIds', fn (array $ids) => ! in_array($entry->id, $ids, true))
            ->call('restore', [$entry->id]);

        $this->assertSame(['restored' => 0, 'failed' => 0, 'conflicts' => 0], app(RestoreChanges::class)->handle([$entry->id]));
        $this->assertSame('Current', $task->fresh()?->label);
    }

    public function test_entries_of_a_person_who_no_longer_exists_show_the_recorded_name(): void
    {
        $this->actingAs($this->admin);
        $gone = User::factory()->create();
        $goneId = $gone->id;
        $gone->forceDelete();
        $morph = (new User)->getMorphClass();

        $this->importedEntry('users', 'updated', $morph, $goneId, ['old' => ['name' => 'Gina Gone'], 'attributes' => ['name' => 'Gina Gone']]);
        $this->importedEntry('users', 'deleted', $morph, null, ['attributes' => ['name' => 'Nora Never']]);
        $this->importedEntry('users', ChangeUserRole::EVENT, $morph, null, ['old' => ['role' => 'technician', 'name' => 'Rita Role'], 'attributes' => ['role' => 'supervisor', 'name' => 'Rita Role']]);
        $this->importedEntry('tasks', 'deleted', (new Task)->getMorphClass(), null, ['attributes' => ['user_id' => $goneId, 'label' => 'Splicing']]);

        Livewire::test(LogIndex::class)
            ->assertOk()
            ->assertSee('Gina Gone')
            ->assertSee('Nora Never')
            ->assertSee('Rita Role ('.__('roles.technician').' → '.__('roles.supervisor').')')
            ->assertSee('Splicing');
    }

    public function test_an_author_without_an_account_is_shown_by_the_recorded_name(): void
    {
        $this->actingAs($this->admin);
        $task = Task::factory()->create();
        $morph = $task->getMorphClass();

        $this->importedEntry('tasks', 'created', $morph, $task->id, ['attributes' => ['label' => 'Named']], ['author_name' => 'Paula Past']);
        $this->importedEntry('tasks', 'created', $morph, $task->id, ['attributes' => ['label' => 'Anonymous']]);

        Livewire::test(LogIndex::class)
            ->assertSee('Paula Past')
            ->assertSee(__('logs.unknown_user'));
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $properties
     */
    private function importedEntry(string $type, string $event, string $subjectType, ?int $subjectId, array $changes, array $properties = []): Activity
    {
        $id = DB::table('activity_log')->insertGetId([
            'log_name' => $type,
            'description' => $event,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'attribute_changes' => json_encode($changes),
            'properties' => json_encode(['batch' => fake()->uuid(), 'legacy' => true, ...$properties]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Activity::query()->findOrFail($id);
    }

    private function securityLog(): TestHandler
    {
        config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        Log::forgetChannel(SecurityLog::CHANNEL);

        $logger = Log::channel(SecurityLog::CHANNEL);
        $this->assertInstanceOf(Logger::class, $logger);
        $monolog = $logger->getLogger();
        $this->assertInstanceOf(MonologLogger::class, $monolog);
        $handler = $monolog->getHandlers()[0];
        $this->assertInstanceOf(TestHandler::class, $handler);

        return $handler;
    }
}
