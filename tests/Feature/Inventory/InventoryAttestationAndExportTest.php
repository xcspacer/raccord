<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\CreateAttestation;
use App\Enums\InventoryAttestationStatus;
use App\Enums\InventoryStatus;
use App\Enums\Role;
use App\Inventory\InventoryException;
use App\Livewire\Inventory\Attestation;
use App\Livewire\Inventory\Panel;
use App\Models\InventoryAttestation;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\StoredFile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class InventoryAttestationAndExportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $alice;

    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['name' => 'Sam']);
        $this->alice = User::factory()->withRole(Role::Technician)->create(['name' => 'Alice']);
        $this->bruno = User::factory()->withRole(Role::Technician)->create(['name' => 'Bruno']);
    }

    private function item(User $holder, string $label, InventoryStatus $status = InventoryStatus::Assigned): InventoryItem
    {
        return InventoryItem::query()->create(['label' => $label, 'status' => $status, 'holder_id' => $holder->id, 'quantity' => 2]);
    }

    private function signature(bool $blank = false): string
    {
        $image = imagecreatetruecolor(300, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));

        if (! $blank) {
            imagesetthickness($image, 3);
            imageline($image, 10, 80, 280, 20, imagecolorallocate($image, 0, 0, 0));
        }

        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    public function test_a_certificate_with_both_signatures_is_signed_and_only_covers_what_the_person_holds(): void
    {
        $mine = $this->item($this->alice, 'Drill');
        $other = $this->item($this->bruno, 'Saw');
        $stock = InventoryItem::query()->create(['label' => 'Ladder', 'status' => InventoryStatus::Stock]);
        $lost = $this->item($this->alice, 'Lost thing', InventoryStatus::Lost);

        $attestation = app(CreateAttestation::class)->handle($this->manager, $this->alice, [$mine->id, $other->id, $stock->id, $lost->id], [
            'date' => '2026-11-03', 'observations' => 'All good', 'representative' => 'Sam S.', 'technician_signature' => $this->signature(), 'representative_signature' => $this->signature(),
        ]);

        $this->assertSame(InventoryAttestationStatus::Signed, $attestation->status);
        $this->assertStringStartsWith('AT-', $attestation->reference);
        $this->assertSame([$mine->id], $attestation->items()->pluck('inventory_items.id')->all());
        $this->assertSame('2026-11-03', $attestation->delivered_on->toDateString());
        $this->assertSame('Sam S.', $attestation->representative_name);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($attestation->file->path));
        $this->assertSame('attest', $mine->movements()->latest('id')->first()->type->value);
        $this->assertSame(0, $other->movements()->count());
    }

    public function test_a_missing_blank_or_invalid_signature_makes_the_certificate_unsigned(): void
    {
        $item = $this->item($this->alice, 'Drill');
        $create = fn (?string $technician, ?string $representative) => app(CreateAttestation::class)->handle($this->manager, $this->alice, [$item->id], ['technician_signature' => $technician, 'representative_signature' => $representative]);

        $this->assertSame(InventoryAttestationStatus::Unsigned, $create($this->signature(), null)->status);
        $this->assertSame(InventoryAttestationStatus::Unsigned, $create($this->signature(), $this->signature(true))->status);
        $this->assertSame(InventoryAttestationStatus::Unsigned, $create($this->signature(), 'data:image/png;base64,bm90IGFuIGltYWdl')->status);
        $this->assertSame(InventoryAttestationStatus::Unsigned, $create($this->signature(), '<script>')->status);

        $mixed = $create($this->signature(), $this->signature());
        $this->assertTrue($mixed->technician_signed && $mixed->representative_signed);
    }

    public function test_a_certificate_needs_at_least_one_held_item(): void
    {
        $other = $this->item($this->bruno, 'Saw');

        $this->expectException(InventoryException::class);
        app(CreateAttestation::class)->handle($this->manager, $this->alice, [$other->id], []);
    }

    public function test_the_signed_badge_follows_the_holder(): void
    {
        $item = $this->item($this->alice, 'Drill');
        app(CreateAttestation::class)->handle($this->manager, $this->alice, [$item->id], ['technician_signature' => $this->signature(), 'representative_signature' => $this->signature()]);

        Livewire::actingAs($this->manager)->test(Panel::class, ['mode' => 'central'])->assertSee(__('inventory.signed_handover'));

        $item->update(['holder_id' => $this->bruno->id]);
        Livewire::actingAs($this->manager)->test(Panel::class, ['mode' => 'central'])->assertDontSee(__('inventory.signed_handover'))->assertDontSee(__('inventory.unsigned_handover'));

        $other = $this->item($this->alice, 'Saw');
        app(CreateAttestation::class)->handle($this->manager, $this->alice, [$other->id], []);
        Livewire::actingAs($this->manager)->test(Panel::class, ['mode' => 'central'])->assertSee(__('inventory.unsigned_handover'));
    }

    public function test_an_old_certificate_is_never_counted_as_signed_and_its_original_pdf_is_served_as_is(): void
    {
        $item = $this->item($this->alice, 'Drill');
        Storage::disk('local')->put('files/original.pdf', '%PDF-1.4 original');
        $file = StoredFile::query()->create(['sha256' => hash('sha256', '%PDF-1.4 original'), 'disk' => 'local', 'path' => 'files/original.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 17]);
        $old = InventoryAttestation::query()->create([
            'reference' => 'AT-OLD1', 'user_id' => $this->alice->id, 'delivered_on' => '2025-03-01', 'representative_name' => 'Sam S.',
            'technician_signed' => true, 'representative_signed' => true, 'status' => InventoryAttestationStatus::Unverified,
            'created_by_name' => 'Sam', 'stored_file_id' => $file->id, 'created_at' => now(),
        ]);
        $old->items()->attach($item->id);

        $this->assertFalse($old->fresh()?->isSigned());
        Livewire::actingAs($this->manager)->test(Panel::class, ['mode' => 'central'])
            ->assertSee(__('inventory.unverified_handover'))->assertDontSee(__('inventory.signed_handover'))->assertDontSee(__('inventory.unsigned_handover'));
        Livewire::actingAs($this->alice)->test(Panel::class, ['mode' => 'mine'])->call('setTab', 'attestations')
            ->assertSee('AT-OLD1')->assertSee(InventoryAttestationStatus::Unverified->label());

        $response = $this->actingAs($this->alice)->get(route('equipment.attestations.show', $old))->assertOk();
        $this->assertSame('%PDF-1.4 original', $response->streamedContent());
        $this->actingAs($this->bruno)->get(route('equipment.attestations.show', $old))->assertNotFound();

        app(CreateAttestation::class)->handle($this->manager, $this->alice, [$item->id], ['technician_signature' => $this->signature(), 'representative_signature' => $this->signature()]);
        Livewire::actingAs($this->manager)->test(Panel::class, ['mode' => 'central'])->assertSee(__('inventory.signed_handover'))->assertDontSee(__('inventory.unverified_handover'));
    }

    public function test_the_modal_warns_about_missing_signatures_and_then_archives(): void
    {
        $item = $this->item($this->alice, 'Drill');
        $this->item($this->alice, 'Pending', InventoryStatus::ToConfirm);

        $component = Livewire::actingAs($this->manager)->test(Attestation::class)->call('openFor', $this->alice->id);
        $this->assertSame([$item->id], $component->get('ids'));

        $component->call('archive', false)->assertSet('unsignedWarning', true);
        $this->assertDatabaseCount('inventory_attestations', 0);

        $component->call('showPreview')->assertHasNoErrors();
        $this->assertNotNull($component->get('preview'));

        $component->set('ids', [])->call('archive', true)->assertHasErrors('form');

        $component->set('ids', [$item->id])->set('technicianSignature', $this->signature())->call('archive', true)->assertDispatched('toast');
        $this->assertSame(InventoryAttestationStatus::Unsigned, InventoryAttestation::query()->sole()->status);

        Livewire::actingAs($this->alice)->test(Attestation::class)->call('openFor', $this->alice->id)->assertForbidden();
    }

    public function test_the_certificate_pdf_is_for_managers_and_its_holder_only(): void
    {
        $item = $this->item($this->alice, 'Drill');
        $attestation = app(CreateAttestation::class)->handle($this->manager, $this->alice, [$item->id], []);
        $url = route('equipment.attestations.show', $attestation);

        $this->actingAs($this->alice)->get($url)->assertOk();
        $this->actingAs($this->manager)->get($url)->assertOk();
        $this->actingAs($this->bruno)->get($url)->assertNotFound();
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_the_certificates_tab_lists_them_for_the_manager_and_the_holder(): void
    {
        $item = $this->item($this->alice, 'Drill');
        $attestation = app(CreateAttestation::class)->handle($this->manager, $this->alice, [$item->id], []);
        $this->item($this->bruno, 'Saw');
        $other = app(CreateAttestation::class)->handle($this->manager, $this->bruno, [InventoryItem::query()->where('label', 'Saw')->first()->id], []);

        Livewire::actingAs($this->manager)->test(Panel::class, ['mode' => 'user', 'userId' => $this->alice->id])->call('setTab', 'attestations')->assertSee($attestation->reference)->assertDontSee($other->reference);
        Livewire::actingAs($this->alice)->test(Panel::class, ['mode' => 'mine'])->call('setTab', 'attestations')->assertSee($attestation->reference)->assertDontSee($other->reference);
    }

    public function test_the_excel_export_has_an_inventory_and_a_history_sheet(): void
    {
        $this->item($this->alice, 'Drill');
        InventoryItem::query()->create(['label' => 'Retired meter', 'status' => InventoryStatus::Retired]);
        InventoryMovement::query()->create(['inventory_item_id' => InventoryItem::query()->first()->id, 'type' => 'assign', 'user_id' => $this->manager->id, 'user_name' => 'Sam', 'label' => 'Drill', 'to_user_id' => $this->alice->id, 'to_name' => 'Alice', 'created_at' => now()]);

        $response = $this->actingAs($this->manager)->get(route('equipment.export', ['format' => 'excel']));
        $response->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);

        $this->assertSame(['Inventory', 'History'], $book->getSheetNames());
        $inventory = $book->getSheet(0);
        $this->assertSame('Designation', $inventory->getCell('B1')->getValue());
        $this->assertSame('Drill', $inventory->getCell('B2')->getValue());
        $this->assertSame('Retired meter', $inventory->getCell('B3')->getValue());
        $this->assertSame('Assigned', $inventory->getCell('I3')->getValue() === 'Out of service' ? 'Assigned' : 'x');
        $this->assertSame('Alice', $book->getSheet(1)->getCell('F2')->getValue());

        $personal = $this->actingAs($this->manager)->get(route('equipment.export', ['format' => 'excel', 'user' => $this->bruno->id]));
        file_put_contents($path, $personal->streamedContent());
        $this->assertNull(IOFactory::load($path)->getSheet(0)->getCell('B2')->getValue());
        $this->assertStringContainsString('Bruno', (string) $personal->headers->get('Content-Disposition'));
    }

    public function test_the_pdf_export_excludes_retired_lines_and_is_for_managers_only(): void
    {
        $this->item($this->alice, 'Drill');

        $response = $this->actingAs($this->manager)->get(route('equipment.export', ['format' => 'pdf']));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $this->actingAs($this->alice)->get(route('equipment.export', ['format' => 'pdf']))->assertForbidden();
        $this->actingAs($this->alice)->get(route('equipment.export', ['format' => 'excel']))->assertForbidden();
        $this->actingAs($this->manager)->get(route('equipment.export', ['format' => 'csv']))->assertNotFound();
    }
}
