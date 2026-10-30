<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\AssignItem;
use App\Actions\Inventory\ConfirmItems;
use App\Actions\Inventory\CreateItems;
use App\Actions\Inventory\DeclareAnomaly;
use App\Actions\Inventory\DeleteItem;
use App\Actions\Inventory\ManageRequest;
use App\Actions\Inventory\ResolveAnomaly;
use App\Actions\Inventory\ReturnItem;
use App\Actions\Inventory\TransferItem;
use App\Actions\Inventory\UpdateItem;
use App\Enums\InventoryAnomalyKind;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryRequestKind;
use App\Enums\InventoryStatus;
use App\Enums\Role;
use App\Inventory\InventoryException;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $alice;

    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['name' => 'Sam']);
        $this->alice = User::factory()->withRole(Role::Technician)->create(['name' => 'Alice']);
        $this->bruno = User::factory()->withRole(Role::Technician)->create(['name' => 'Bruno']);
    }

    private function create(array $row = [], ?User $holder = null, array $options = []): InventoryItem
    {
        $result = app(CreateItems::class)->handle($this->manager, [$row + ['label' => 'Ladder']], $holder, $options);
        $this->assertCount(1, $result['created'], json_encode($result['skipped']));

        return $result['created'][0];
    }

    private function reason(callable $operation): string
    {
        try {
            $operation();
        } catch (InventoryException $exception) {
            return $exception->reason;
        }

        $this->fail('No exception');
    }

    public function test_a_line_created_without_holder_is_in_stock_and_with_a_holder_waits_for_confirmation_or_is_official(): void
    {
        $stock = $this->create(['label' => 'Splicer', 'serial' => 'FX-123', 'quantity' => 5, 'condition' => 'good']);
        $this->assertSame(InventoryStatus::Stock, $stock->status);
        $this->assertTrue($stock->is_serialized);
        $this->assertSame(1, $stock->quantity);
        $this->assertSame('MAT-'.str_pad((string) $stock->id, 4, '0', STR_PAD_LEFT), $stock->reference());

        $noted = $this->create(['label' => 'Drill'], $this->alice, ['site' => 'Depot', 'date' => '2026-11-02']);
        $this->assertSame(InventoryStatus::ToConfirm, $noted->status);
        $this->assertSame('2026-11-02', $noted->assigned_on->toDateString());
        $this->assertSame(InventoryMovementType::Noted, $noted->movements()->sole()->type);

        $official = $this->create(['label' => 'Helmet'], $this->alice, ['official' => true]);
        $this->assertSame(InventoryStatus::Assigned, $official->status);
        $this->assertNotNull($official->confirmed_at);
        $this->assertSame($this->manager->id, $official->confirmed_by);
        $this->assertSame(InventoryMovementType::Create, $official->movements()->sole()->type);
    }

    public function test_invalid_lines_serials_and_duplicates_are_skipped_with_a_reason(): void
    {
        $first = $this->create(['label' => 'Splicer', 'serial' => 'FX-123']);
        $this->create(['label' => 'Gloves', 'brand' => 'Acme', 'quantity' => 10]);

        $result = app(CreateItems::class)->handle($this->manager, [
            ['label' => ''],
            ['label' => 'Another splicer', 'serial' => 'fx 123'],
            ['label' => 'Gloves', 'brand' => 'Acme'],
            ['label' => 'Rope'],
            ['label' => 'Rope'],
        ], null);

        $this->assertSame(['label', 'serial', 'duplicate', 'duplicate'], array_column($result['skipped'], 'reason'));
        $this->assertSame($first->reference(), $result['skipped'][1]['existing']);
        $this->assertCount(1, $result['created']);

        $forced = app(CreateItems::class)->handle($this->manager, [['label' => 'Gloves', 'brand' => 'Acme']], null, ['force_new' => true]);
        $this->assertCount(1, $forced['created']);

        $this->assertSame('too_many_lines', $this->reason(fn () => app(CreateItems::class)->handle($this->manager, array_fill(0, 151, ['label' => 'X']), null)));
    }

    public function test_confirming_only_affects_lines_waiting_for_confirmation(): void
    {
        $noted = $this->create(['label' => 'Drill'], $this->alice);
        $stock = $this->create(['label' => 'Saw']);

        $this->assertSame(1, app(ConfirmItems::class)->handle($this->manager, [$noted->id, $stock->id]));

        $this->assertSame(InventoryStatus::Assigned, $noted->refresh()->status);
        $this->assertNotNull($noted->assigned_on);
        $this->assertSame(InventoryStatus::Stock, $stock->refresh()->status);
    }

    public function test_assigning_from_the_stock_can_split_a_multi_unit_line_but_not_a_serialized_one(): void
    {
        $gloves = $this->create(['label' => 'Gloves', 'quantity' => 10]);

        $part = app(AssignItem::class)->handle($this->manager, $gloves->id, $this->alice->id, 3, 'good', '2026-11-03');

        $this->assertNotSame($gloves->id, $part->id);
        $this->assertSame(3, $part->quantity);
        $this->assertSame($gloves->id, $part->split_from_id);
        $this->assertSame($this->alice->id, $part->holder_id);
        $this->assertSame(InventoryCondition::Good, $part->condition);
        $this->assertSame(7, $gloves->refresh()->quantity);
        $this->assertSame(InventoryStatus::Stock, $gloves->status);

        $this->assertSame('quantity_too_high', $this->reason(fn () => app(AssignItem::class)->handle($this->manager, $gloves->id, $this->alice->id, 8)));
        $this->assertSame('not_available', $this->reason(fn () => app(AssignItem::class)->handle($this->manager, $part->id, $this->bruno->id)));

        $splicer = $this->create(['label' => 'Splicer', 'serial' => 'S1']);
        app(AssignItem::class)->handle($this->manager, $splicer->id, $this->alice->id);
        $this->assertSame($this->alice->id, $splicer->refresh()->holder_id);

        $other = $this->create(['label' => 'Meter', 'serial' => 'S2']);
        $this->assertSame('bad_recipient', $this->reason(fn () => app(AssignItem::class)->handle($this->manager, $other->id, 999999)));
        $designOffice = User::factory()->withRole(Role::DesignOffice)->create();
        $this->assertSame('bad_recipient', $this->reason(fn () => app(AssignItem::class)->handle($this->manager, $other->id, $designOffice->id)));
    }

    public function test_returning_clears_the_holder_and_defective_equipment_goes_to_repair(): void
    {
        $item = $this->create(['label' => 'Drill'], $this->alice, ['official' => true]);

        $returned = app(ReturnItem::class)->handle($this->manager, $item->id, null, 'defective', 'stock');

        $this->assertSame(InventoryStatus::Repair, $returned->status);
        $this->assertNull($returned->holder_id);
        $this->assertNotNull($returned->returned_at);
        $this->assertSame('repair', $returned->movements()->latest('id')->first()->outcome);
        $this->assertSame('not_held', $this->reason(fn () => app(ReturnItem::class)->handle($this->manager, $item->id)));

        $good = $this->create(['label' => 'Saw'], $this->alice, ['official' => true]);
        $this->assertSame(InventoryStatus::Stock, app(ReturnItem::class)->handle($this->manager, $good->id, null, 'good', 'stock')->status);
        $broken = $this->create(['label' => 'Saw 2'], $this->alice, ['official' => true]);
        $this->assertSame(InventoryStatus::Retired, app(ReturnItem::class)->handle($this->manager, $broken->id, null, null, 'retired')->status);
    }

    public function test_a_partial_return_splits_the_line(): void
    {
        $item = $this->create(['label' => 'Gloves', 'quantity' => 6], $this->alice, ['official' => true]);

        $part = app(ReturnItem::class)->handle($this->manager, $item->id, 2, 'good', 'stock');

        $this->assertSame(2, $part->quantity);
        $this->assertSame(InventoryStatus::Stock, $part->status);
        $this->assertSame(4, $item->refresh()->quantity);
        $this->assertSame($this->alice->id, $item->holder_id);
    }

    public function test_transfers_check_the_current_holder_and_the_state(): void
    {
        $item = $this->create(['label' => 'Drill'], $this->alice, ['official' => true]);

        $this->assertSame('holder_changed', $this->reason(fn () => app(TransferItem::class)->handle($this->manager, $item->id, $this->bruno->id, $this->bruno->id)));
        $this->assertSame('bad_recipient', $this->reason(fn () => app(TransferItem::class)->handle($this->manager, $item->id, $this->alice->id, $this->alice->id)));

        $moved = app(TransferItem::class)->handle($this->manager, $item->id, $this->bruno->id, $this->alice->id, null, 'worn');

        $this->assertSame($this->bruno->id, $moved->holder_id);
        $this->assertSame(InventoryStatus::Assigned, $moved->status);
        $movement = $moved->movements()->latest('id')->first();
        $this->assertSame('Alice', $movement->from_name);
        $this->assertSame('Bruno', $movement->to_name);

        app(DeclareAnomaly::class)->handle($this->manager, $item->id, InventoryAnomalyKind::Damaged, 'Fell');
        $this->assertSame('not_transferable', $this->reason(fn () => app(TransferItem::class)->handle($this->manager, $item->id, $this->alice->id, $this->bruno->id)));
    }

    public function test_anomalies_need_a_comment_keep_the_holder_and_are_resolved_by_a_manager(): void
    {
        $item = $this->create(['label' => 'Drill'], $this->alice, ['official' => true]);

        $this->assertSame('comment_required', $this->reason(fn () => app(DeclareAnomaly::class)->handle($this->alice, $item->id, InventoryAnomalyKind::Lost, '  ')));

        $missing = app(DeclareAnomaly::class)->handle($this->alice, $item->id, InventoryAnomalyKind::Missing, 'Not in the van');
        $this->assertSame(InventoryStatus::Lost, $missing->status);
        $this->assertSame('missing', $missing->anomaly['kind']);
        $this->assertSame($this->alice->id, $missing->holder_id);
        $this->assertSame('state_changed', $this->reason(fn () => app(DeclareAnomaly::class)->handle($this->alice, $item->id, InventoryAnomalyKind::Lost, 'Again')));

        $this->assertSame('no_holder', $this->reason(function () {
            $stock = $this->create(['label' => 'Stock line']);
            $stock->update(['status' => InventoryStatus::Lost]);
            app(ResolveAnomaly::class)->handle($this->manager, $stock->id, 'assigned');
        }));

        $found = app(ResolveAnomaly::class)->handle($this->manager, $item->id, 'assigned', 'Found in the depot');
        $this->assertSame(InventoryStatus::Assigned, $found->status);
        $this->assertNull($found->anomaly);
        $this->assertSame(InventoryCondition::Good, $found->condition);

        app(DeclareAnomaly::class)->handle($this->alice, $item->id, InventoryAnomalyKind::Damaged, 'Broken');
        $this->assertSame(InventoryCondition::Defective, $item->refresh()->condition);
        $this->assertNull(app(ResolveAnomaly::class)->handle($this->manager, $item->id, 'stock')->holder_id);
        $this->assertSame('bad_outcome', $this->reason(fn () => app(ResolveAnomaly::class)->handle($this->manager, $item->id, 'lost')));
    }

    public function test_requests_replace_each_other_do_not_change_the_state_and_are_cleared_by_a_return(): void
    {
        $item = $this->create(['label' => 'Drill'], $this->alice, ['official' => true]);

        app(ManageRequest::class)->open($this->alice, $item->id, InventoryRequestKind::Return, 'Not needed');
        app(ManageRequest::class)->open($this->alice, $item->id, InventoryRequestKind::Replacement);
        $item->refresh();
        $this->assertSame('replacement', $item->pending_request['kind']);
        $this->assertSame(InventoryStatus::Assigned, $item->status);

        app(ManageRequest::class)->close($this->manager, $item->id);
        $this->assertNull($item->refresh()->pending_request);

        app(ManageRequest::class)->open($this->alice, $item->id, InventoryRequestKind::Return);
        app(ReturnItem::class)->handle($this->manager, $item->id);
        $this->assertNull($item->refresh()->pending_request);
    }

    public function test_editing_keeps_the_differences_and_validates_the_serial(): void
    {
        $item = $this->create(['label' => 'Drill', 'quantity' => 2]);
        $other = $this->create(['label' => 'Splicer', 'serial' => 'AB-1']);

        $this->assertFalse(app(UpdateItem::class)->handle($this->manager, $item->id, ['label' => 'Drill']));
        $this->assertSame(1, $item->movements()->count());

        $this->assertTrue(app(UpdateItem::class)->handle($this->manager, $item->id, ['label' => 'Drill XL', 'brand' => 'Bosch', 'quantity' => 4, 'condition' => 'bogus', 'needs_check' => true]));
        $item->refresh();
        $this->assertSame('Drill XL', $item->label);
        $this->assertSame(4, $item->quantity);
        $this->assertSame(InventoryCondition::Unknown, $item->condition);
        $edit = $item->movements()->where('type', 'edit')->sole();
        $this->assertSame(['Drill', 'Drill XL'], $edit->details['changes']['label']);

        $this->assertSame('serial_exists', $this->reason(fn () => app(UpdateItem::class)->handle($this->manager, $item->id, ['serial' => 'ab1'])));

        app(UpdateItem::class)->handle($this->manager, $item->id, ['serial' => 'CD-2', 'quantity' => 9]);
        $item->refresh();
        $this->assertTrue($item->is_serialized);
        $this->assertSame(1, $item->quantity);

        app(UpdateItem::class)->handle($this->manager, $item->id, ['label' => '']);
        $this->assertSame('Drill XL', $item->refresh()->label);
        $this->assertNotNull($other->id);
    }

    public function test_deleting_blocks_every_operation_and_restoring_checks_the_serial(): void
    {
        $item = $this->create(['label' => 'Splicer', 'serial' => 'AB-1']);

        app(DeleteItem::class)->delete($this->manager, $item->id, 'Duplicate entry');
        $this->assertTrue($item->refresh()->trashed());
        $this->assertSame($this->manager->id, $item->deleted_by);
        $this->assertSame('item_deleted', $this->reason(fn () => app(AssignItem::class)->handle($this->manager, $item->id, $this->alice->id)));
        $this->assertSame('item_deleted', $this->reason(fn () => app(UpdateItem::class)->handle($this->manager, $item->id, ['label' => 'x'])));

        $this->create(['label' => 'Splicer again', 'serial' => 'AB-1']);
        $this->assertSame('serial_exists', $this->reason(fn () => app(DeleteItem::class)->restore($this->manager, $item->id)));

        $free = $this->create(['label' => 'Saw']);
        app(DeleteItem::class)->delete($this->manager, $free->id);
        app(DeleteItem::class)->restore($this->manager, $free->id);
        $this->assertFalse($free->refresh()->trashed());
        $this->assertNull($free->deleted_comment);
        $this->assertSame(['create', 'delete', 'restore'], $free->movements()->orderBy('id')->pluck('type')->map(fn ($type) => $type->value)->all());
    }

    public function test_the_history_is_append_only_and_keeps_the_names_as_they_were(): void
    {
        $item = $this->create(['label' => 'Drill'], $this->alice, ['official' => true]);
        $this->alice->update(['name' => 'Alice Renamed']);

        $this->assertSame('Alice', InventoryMovement::query()->where('inventory_item_id', $item->id)->sole()->holder_name);
        $this->assertSame('Sam', InventoryMovement::query()->sole()->user_name);
    }
}
