<?php

namespace Tests\Feature\Inventory;

use App\Ai\EquipmentLabelReader;
use App\Enums\EquipmentReadStatus;
use App\Enums\InventoryStatus;
use App\Enums\Role;
use App\Jobs\ReadEquipmentLabel;
use App\Livewire\Admin\Users\UserCard;
use App\Livewire\Inventory\ItemSheet;
use App\Livewire\Inventory\Panel;
use App\Livewire\TechnicianSpace\Space;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\PublicHolidaySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class LabelPhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.url' => 'https://anthropic.test/v1/messages']);
        $this->seed([RoleSeeder::class, PublicHolidaySeeder::class]);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['name' => 'Alice']);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create();
    }

    private function answer(array $payload): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['anthropic.test/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($payload)]]])]);
    }

    private function photograph(?User $user = null): InventoryItem
    {
        Bus::fake();
        Livewire::actingAs($user ?? $this->technician)->test(Panel::class, ['mode' => 'mine'])->set('labelPhoto', UploadedFile::fake()->image('label.jpg', 900, 600))->assertHasNoErrors();

        return InventoryItem::query()->latest('id')->firstOrFail();
    }

    public function test_a_label_photo_creates_a_noted_line_in_the_persons_name_being_read(): void
    {
        $item = $this->photograph();

        $this->assertSame(InventoryStatus::ToConfirm, $item->status);
        $this->assertSame($this->technician->id, $item->holder_id);
        $this->assertSame($this->technician->id, $item->created_by);
        $this->assertSame(EquipmentReadStatus::Reading, $item->read_status);
        $this->assertSame(1, $item->photos()->count());
        Bus::assertDispatchedAfterResponse(ReadEquipmentLabel::class, fn (ReadEquipmentLabel $job) => $job->itemId === $item->id && $job->userId === $this->technician->id);
    }

    public function test_only_images_and_only_the_own_mine_panel_accept_label_photos(): void
    {
        Livewire::actingAs($this->technician)->test(Panel::class, ['mode' => 'mine'])->set('labelPhoto', UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'))->assertHasErrors('labelPhoto');
        Livewire::actingAs(User::factory()->withRole(Role::DesignOffice)->create())->test(Panel::class, ['mode' => 'mine'])->assertForbidden();

        $this->assertDatabaseCount('inventory_items', 0);
    }

    public function test_the_ai_fills_the_line_from_the_label(): void
    {
        $item = $this->photograph();
        $this->answer(['type' => 'welder', 'brand' => 'Fujikura', 'model' => '90S+', 'serial_number' => 'FX-1234', 'confidence' => 'high']);

        new ReadEquipmentLabel($this->technician->id, $item->id)->handle(app(EquipmentLabelReader::class));

        $item->refresh();
        $this->assertSame('Fujikura 90S+', $item->label);
        $this->assertSame('welder', $item->category);
        $this->assertTrue($item->is_serialized);
        $this->assertSame('FX-1234', $item->serial);
        $this->assertSame(EquipmentReadStatus::Ok, $item->read_status);
        $this->assertFalse($item->needs_check);
        $this->assertSame(InventoryStatus::ToConfirm, $item->status);
    }

    public function test_a_doubtful_reading_or_an_unreadable_label_is_flagged(): void
    {
        $item = $this->photograph();
        $this->answer(['type' => 'drill', 'brand' => '', 'model' => '', 'serial_number' => '', 'confidence' => 'low']);
        new ReadEquipmentLabel($this->technician->id, $item->id)->handle(app(EquipmentLabelReader::class));

        $item->refresh();
        $this->assertSame(EquipmentReadStatus::Check, $item->read_status);
        $this->assertTrue($item->needs_check);
        $this->assertSame('power_tool', $item->category);
        $this->assertSame(__('enums.inventory_categories.power_tool'), $item->label);

        config(['services.anthropic.key' => '']);
        $second = $this->photograph();
        new ReadEquipmentLabel($this->technician->id, $second->id)->handle(app(EquipmentLabelReader::class));
        $second->refresh();
        $this->assertSame(EquipmentReadStatus::Manual, $second->read_status);
        $this->assertSame('not_configured', $second->read_message);
        $this->assertSame(__('inventory.label.unnamed'), $second->label);
    }

    public function test_a_serial_already_registered_elsewhere_is_left_out_and_on_the_same_person_the_photo_joins_the_line(): void
    {
        InventoryItem::query()->create(['label' => 'Stock splicer', 'serial' => 'FX-1234', 'is_serialized' => true, 'status' => InventoryStatus::Stock]);
        $item = $this->photograph();
        $this->answer(['type' => 'welder', 'brand' => 'Fujikura', 'model' => '90S+', 'serial_number' => 'fx 1234', 'confidence' => 'high']);
        new ReadEquipmentLabel($this->technician->id, $item->id)->handle(app(EquipmentLabelReader::class));

        $item->refresh();
        $this->assertNull($item->serial);
        $this->assertTrue($item->needs_check);
        $this->assertSame(EquipmentReadStatus::Check, $item->read_status);
        $this->assertStringContainsString('MAT-', (string) $item->uncertainty);

        $mine = InventoryItem::query()->create(['label' => 'Own splicer', 'serial' => 'ZZ-9', 'is_serialized' => true, 'status' => InventoryStatus::Assigned, 'holder_id' => $this->technician->id]);
        $again = $this->photograph();
        $this->answer(['type' => 'welder', 'brand' => 'X', 'model' => 'Y', 'serial_number' => 'ZZ-9', 'confidence' => 'high']);
        new ReadEquipmentLabel($this->technician->id, $again->id)->handle(app(EquipmentLabelReader::class));

        $this->assertNull(InventoryItem::query()->find($again->id));
        $this->assertSame(1, $mine->photos()->count());
    }

    public function test_a_reading_is_only_done_for_the_owner_once(): void
    {
        $item = $this->photograph();
        Http::fake();

        new ReadEquipmentLabel($this->manager->id, $item->id)->handle(app(EquipmentLabelReader::class));
        $this->assertSame(EquipmentReadStatus::Reading, $item->refresh()->read_status);
        Http::assertNothingSent();
    }

    public function test_the_person_edits_and_removes_what_they_noted_until_a_manager_confirms_it(): void
    {
        $item = $this->photograph();
        $item->update(['read_status' => EquipmentReadStatus::Ok, 'brand' => 'Bosch']);

        $sheet = Livewire::actingAs($this->technician)->test(ItemSheet::class)->call('openFor', $item->id)->call('startAction', 'edit')
            ->set('form.label', 'Drill')->set('form.brand', 'Makita')->call('submit')->assertHasNoErrors();
        $item->refresh();
        $this->assertSame('Makita', $item->brand);
        $this->assertSame(EquipmentReadStatus::Manual, $item->read_status);

        Livewire::actingAs($this->technician)->test(ItemSheet::class)->call('openFor', $item->id)->call('startAction', 'delete')->call('submit');
        $this->assertTrue($item->refresh()->trashed());

        $official = InventoryItem::query()->create(['label' => 'Official', 'status' => InventoryStatus::Assigned, 'holder_id' => $this->technician->id, 'created_by' => $this->technician->id]);
        Livewire::actingAs($this->technician)->test(ItemSheet::class)->call('openFor', $official->id)->call('startAction', 'edit')->assertForbidden();
        $foreign = InventoryItem::query()->create(['label' => 'Foreign', 'status' => InventoryStatus::ToConfirm, 'holder_id' => $this->technician->id, 'created_by' => $this->manager->id]);
        Livewire::actingAs($this->technician)->test(ItemSheet::class)->call('openFor', $foreign->id)->call('startAction', 'edit')->assertForbidden();
        $this->assertNotNull($sheet);
    }

    public function test_the_technician_sees_the_fused_list_in_mon_espace_and_the_pdf_sheet(): void
    {
        $this->photograph();
        InventoryItem::query()->create(['label' => 'Fujikura 90S+', 'category' => 'welder', 'status' => InventoryStatus::Assigned, 'holder_id' => $this->technician->id]);

        Livewire::actingAs($this->technician)->withQueryParams(['tab' => 'inventory'])->test(Space::class)->assertSee(__('inventory.label.take_photo'))->assertSee('Fujikura 90S+');

        $response = $this->actingAs($this->technician)->get(route('equipment-report'));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->actingAs($this->manager)->get(route('equipment-report'))->assertForbidden();
    }

    public function test_the_user_card_has_no_free_text_tool_fields_any_more(): void
    {
        $admin = User::factory()->withRole(Role::Administrator)->create();

        Livewire::actingAs($admin)->test(UserCard::class, ['user' => $this->technician])->assertDontSee('assignedWelder')->assertSee(__('inventory.assigned_equipment'));
        $this->assertFalse(Schema::hasColumn('users', 'assigned_welder'));
    }
}
