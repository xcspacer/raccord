<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryCondition;
use App\Enums\InventoryStatus;
use App\Enums\Role;
use App\Livewire\Inventory\ItemSheet;
use App\Livewire\Inventory\Panel;
use App\Livewire\TechnicianSpace\Space;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Settings\ModuleSettings;
use Database\Seeders\PublicHolidaySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $alice;

    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PublicHolidaySeeder::class]);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['name' => 'Sam']);
        $this->alice = User::factory()->withRole(Role::Technician)->create(['name' => 'Alice']);
        $this->bruno = User::factory()->withRole(Role::Technician)->create(['name' => 'Bruno']);
    }

    private function item(array $attributes = []): InventoryItem
    {
        return InventoryItem::query()->create($attributes + ['label' => 'Drill', 'status' => InventoryStatus::Stock]);
    }

    public function test_only_managers_with_the_feature_reach_the_pages(): void
    {
        $this->actingAs($this->manager)->get(route('equipment.index'))->assertOk();
        $this->actingAs($this->manager)->get(route('equipment.users.show', ['person' => $this->alice->id]))->assertOk()->assertSee('Alice');
        $this->actingAs(User::factory()->withRole(Role::Administrator)->create())->get(route('equipment.index'))->assertOk();

        $this->actingAs($this->alice)->get(route('equipment.index'))->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::DesignOffice)->create())->get(route('equipment.index'))->assertForbidden();
        $this->actingAs($this->manager)->get(route('equipment.users.show', ['person' => User::factory()->withRole(Role::DesignOffice)->create()->id]))->assertNotFound();

        auth()->logout();
        $this->get(route('equipment.index'))->assertRedirect(route('login'));
    }

    public function test_the_policy_allows_each_action_by_role_holder_and_state(): void
    {
        $stock = $this->item();
        $held = $this->item(['status' => InventoryStatus::Assigned, 'holder_id' => $this->alice->id]);
        $noted = $this->item(['status' => InventoryStatus::ToConfirm, 'holder_id' => $this->alice->id, 'created_by' => $this->alice->id]);
        $lost = $this->item(['status' => InventoryStatus::Lost, 'holder_id' => $this->alice->id]);
        $trashed = tap($this->item())->delete();
        $allowed = fn (User $user, InventoryItem $item) => array_values(array_filter(
            ['confirm', 'assign', 'takeBack', 'transfer', 'resolve', 'closeRequest', 'update', 'delete', 'restore', 'managePhotos', 'addPhotos', 'declareAnomaly', 'makeRequest'],
            fn (string $ability) => $user->can($ability, $item),
        ));

        $this->assertSame(['assign', 'update', 'delete', 'managePhotos', 'addPhotos'], $allowed($this->manager, $stock));
        $this->assertSame(['takeBack', 'transfer', 'update', 'delete', 'managePhotos', 'addPhotos', 'declareAnomaly'], $allowed($this->manager, $held));
        $this->assertSame(['confirm', 'takeBack', 'transfer', 'update', 'delete', 'managePhotos', 'addPhotos', 'declareAnomaly'], $allowed($this->manager, $noted));
        $this->assertSame(['takeBack', 'resolve', 'update', 'delete', 'managePhotos', 'addPhotos'], $allowed($this->manager, $lost));
        $this->assertSame(['restore'], $allowed($this->manager, $trashed));
        $this->assertSame(['addPhotos', 'declareAnomaly', 'makeRequest'], $allowed($this->alice, $held));
        $this->assertSame(['update', 'delete', 'addPhotos', 'declareAnomaly'], $allowed($this->alice, $noted));
        $this->assertSame([], $allowed($this->bruno, $held));
        $this->assertSame([], $allowed($this->alice, $trashed));
    }

    public function test_the_module_switch_blocks_the_pages(): void
    {
        $modules = app(ModuleSettings::class);
        $modules->enabled = array_values(array_diff($modules->enabled, ['equipment']));
        $modules->save();

        $this->actingAs($this->manager)->get(route('equipment.index'))->assertNotFound();
    }

    public function test_the_central_list_shows_kpis_sections_search_and_filters(): void
    {
        $this->item(['label' => 'Splicer', 'serial' => 'FX-1']);
        $this->item(['label' => 'Gloves', 'quantity' => 4, 'status' => InventoryStatus::Assigned, 'holder_id' => $this->alice->id]);
        $this->item(['label' => 'Saw', 'status' => InventoryStatus::ToConfirm, 'holder_id' => $this->bruno->id]);
        $this->item(['label' => 'Broken ladder', 'status' => InventoryStatus::Damaged, 'holder_id' => $this->bruno->id, 'condition' => InventoryCondition::Defective]);
        $this->item(['label' => 'Old meter', 'status' => InventoryStatus::Retired]);

        $component = Livewire::actingAs($this->manager)->test(Panel::class, ['mode' => 'central'])
            ->assertSee('Splicer')->assertSee('Gloves')->assertSee('Saw')->assertSee('Broken ladder')->assertSee('Old meter');

        $component->set('filter', 'stock')->assertSee('Splicer')->assertDontSee('Gloves')->assertDontSee('Old meter');
        $component->set('filter', 'all')->set('search', 'bruno saw')->assertSee('Saw')->assertDontSee('Splicer')->assertDontSee('Broken ladder');
        $component->set('search', 'zzz')->assertSee(__('inventory.no_match'));
    }

    public function test_deleted_lines_only_show_to_managers_in_their_filter(): void
    {
        $line = $this->item(['label' => 'Deleted thing']);
        $line->delete();

        Livewire::actingAs($this->manager)->test(Panel::class, ['mode' => 'central'])->assertDontSee('Deleted thing')->set('filter', 'deleted')->assertSee('Deleted thing');
    }

    public function test_a_technician_sees_only_what_they_hold_in_mon_espace(): void
    {
        $this->item(['label' => 'Mine', 'status' => InventoryStatus::Assigned, 'holder_id' => $this->alice->id, 'quantity' => 2]);
        $this->item(['label' => 'Not mine', 'status' => InventoryStatus::Assigned, 'holder_id' => $this->bruno->id]);
        $this->item(['label' => 'Stock thing']);

        Livewire::actingAs($this->alice)->withQueryParams(['tab' => 'inventory'])->test(Space::class)->assertSee('Mine')->assertDontSee('Not mine')->assertDontSee('Stock thing');

        $mine = Livewire::actingAs($this->alice)->test(Panel::class, ['mode' => 'mine']);
        $mine->assertSee('Mine')->assertDontSee('Not mine')->assertDontSee(__('inventory.new_equipment'));
        $this->assertSame($this->alice->id, $mine->get('userId'));

        Livewire::actingAs($this->alice)->test(Panel::class, ['mode' => 'central'])->assertForbidden();
        Livewire::actingAs($this->alice)->test(Panel::class, ['mode' => 'user', 'userId' => $this->bruno->id])->assertForbidden();
        Livewire::actingAs(User::factory()->withRole(Role::DesignOffice)->create())->test(Panel::class, ['mode' => 'mine'])->assertForbidden();
    }

    public function test_a_technician_cannot_open_equipment_of_somebody_else(): void
    {
        $other = $this->item(['status' => InventoryStatus::Assigned, 'holder_id' => $this->bruno->id]);
        $stock = $this->item();

        Livewire::actingAs($this->alice)->test(ItemSheet::class)->call('openFor', $other->id)->assertNotFound();
        Livewire::actingAs($this->alice)->test(ItemSheet::class)->call('openFor', $stock->id)->assertNotFound();
        Livewire::actingAs($this->alice)->test(ItemSheet::class)->call('openNew')->assertForbidden();
    }

    public function test_the_holder_can_declare_an_anomaly_or_ask_but_not_manage(): void
    {
        $mine = $this->item(['status' => InventoryStatus::Assigned, 'holder_id' => $this->alice->id]);

        $sheet = Livewire::actingAs($this->alice)->test(ItemSheet::class)->call('openFor', $mine->id)
            ->assertSee(__('inventory.actions.anomaly'))->assertSee(__('inventory.actions.request'))->assertDontSee(__('inventory.actions.delete'))->assertDontSee(__('inventory.actions.edit'));

        foreach (['assign', 'return', 'transfer', 'edit', 'delete', 'resolve'] as $forbidden) {
            Livewire::actingAs($this->alice)->test(ItemSheet::class)->call('openFor', $mine->id)->call('startAction', $forbidden)->assertForbidden();
        }

        $sheet->call('startAction', 'request')->set('form.kind', 'replacement')->set('form.comment', 'Worn out')->call('submit')->assertDispatched('toast');
        $this->assertSame('replacement', $mine->refresh()->pending_request['kind']);
        $this->assertSame(InventoryStatus::Assigned, $mine->status);

        $sheet = Livewire::actingAs($this->alice)->test(ItemSheet::class)->call('openFor', $mine->id)->call('startAction', 'anomaly')->set('form.comment', '')->call('submit')->assertHasErrors('form.comment');
        $sheet->set('form.kind', 'lost')->set('form.comment', 'Left on site')->call('submit')->assertDispatched('inventory-changed');
        $this->assertSame(InventoryStatus::Lost, $mine->refresh()->status);
    }

    public function test_a_manager_records_assigns_confirms_and_transfers_through_the_sheet(): void
    {
        $sheet = Livewire::actingAs($this->manager)->test(ItemSheet::class)->call('openNew', $this->alice->id)
            ->set('form.label', '')->call('submit')->assertHasErrors('form.label')
            ->set('form.label', 'Splicer')->set('form.serial', 'AB-12')->set('form.initial', 'to_confirm')->call('submit')->assertDispatched('inventory-changed');

        $item = InventoryItem::query()->sole();
        $this->assertSame(InventoryStatus::ToConfirm, $item->status);
        $this->assertSame($this->alice->id, $item->holder_id);

        Livewire::actingAs($this->manager)->test(ItemSheet::class)->call('openNew')->set('form.label', 'Other')->set('form.serial', 'ab 12')->call('submit')->assertHasErrors('form');
        $this->assertDatabaseCount('inventory_items', 1);

        Livewire::actingAs($this->manager)->test(ItemSheet::class)->call('openFor', $item->id)->call('confirmHandover');
        $this->assertSame(InventoryStatus::Assigned, $item->refresh()->status);

        $transfer = Livewire::actingAs($this->manager)->test(ItemSheet::class)->call('openFor', $item->id)->call('startAction', 'transfer')->set('form.recipient', (string) $this->bruno->id);
        $item->update(['holder_id' => $this->manager->id]);
        $transfer->call('submit')->assertHasErrors('form');
        $this->assertSame($this->manager->id, $item->refresh()->holder_id);
    }

    public function test_assigning_from_the_stock_in_the_user_file_and_confirming_everything(): void
    {
        $stock = $this->item(['label' => 'Spare drill']);
        $this->item(['label' => 'Saw', 'status' => InventoryStatus::ToConfirm, 'holder_id' => $this->alice->id, 'quantity' => 2]);

        $panel = Livewire::actingAs($this->manager)->test(Panel::class, ['mode' => 'user', 'userId' => $this->alice->id])->assertSee('Spare drill')->call('assignFromStock', $stock->id)->assertDispatched('inventory-assign');

        Livewire::actingAs($this->manager)->test(ItemSheet::class)->call('openAssign', $stock->id, $this->alice->id)->call('submit')->assertDispatched('toast');
        $this->assertSame($this->alice->id, $stock->refresh()->holder_id);

        $panel->call('confirmAll');
        $this->assertSame(0, InventoryItem::query()->where('status', 'to_confirm')->count());

        Livewire::actingAs($this->alice)->test(Panel::class, ['mode' => 'mine'])->call('confirmAll')->assertForbidden();
    }

    public function test_the_by_user_tab_lists_people_with_equipment(): void
    {
        $this->item(['label' => 'A', 'status' => InventoryStatus::ToConfirm, 'holder_id' => $this->alice->id, 'quantity' => 3]);
        $this->item(['label' => 'B', 'status' => InventoryStatus::Assigned, 'holder_id' => $this->alice->id]);
        $this->item(['label' => 'C', 'status' => InventoryStatus::Assigned, 'holder_id' => $this->bruno->id]);

        $cards = Livewire::actingAs($this->manager)->test(Panel::class, ['mode' => 'central'])->call('setTab', 'by-user');
        $cards->assertSeeInOrder(['Alice', 'Bruno'])->assertSee(route('equipment.users.show', ['person' => $this->alice->id]));
    }

    public function test_the_history_tab_shows_the_movements_of_the_scope(): void
    {
        $item = $this->item();
        InventoryMovement::query()->create(['inventory_item_id' => $item->id, 'type' => 'assign', 'user_id' => $this->manager->id, 'user_name' => 'Sam', 'label' => 'Drill', 'to_user_id' => $this->alice->id, 'to_name' => 'Alice', 'created_at' => now()]);
        InventoryMovement::query()->create(['inventory_item_id' => $item->id, 'type' => 'assign', 'user_id' => $this->manager->id, 'user_name' => 'Sam', 'label' => 'Ladder', 'to_user_id' => $this->bruno->id, 'to_name' => 'Bruno', 'created_at' => now()]);

        Livewire::actingAs($this->manager)->test(Panel::class, ['mode' => 'central'])->call('setTab', 'history')->assertSee('Drill')->assertSee('Ladder');
        Livewire::actingAs($this->alice)->test(Panel::class, ['mode' => 'mine'])->call('setTab', 'history')->assertSee('Drill')->assertDontSee('Ladder');
    }
}
