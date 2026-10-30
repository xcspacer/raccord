<?php

namespace Tests\Feature\Inventory;

use App\Ai\AiException;
use App\Ai\InventoryPhotoAnalyzer;
use App\Enums\InventoryStatus;
use App\Enums\Role;
use App\Inventory\InventoryListParser;
use App\Livewire\Inventory\BulkAdd;
use App\Livewire\Inventory\ItemSheet;
use App\Models\InventoryItem;
use App\Models\InventoryItemPhoto;
use App\Models\StoredFile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryPhotosAndBulkTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $alice;

    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.url' => 'https://anthropic.test/v1/messages']);
        $this->seed(RoleSeeder::class);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['name' => 'Sam']);
        $this->alice = User::factory()->withRole(Role::Technician)->create(['name' => 'Alice']);
        $this->bruno = User::factory()->withRole(Role::Technician)->create(['name' => 'Bruno']);
    }

    private function item(array $attributes = []): InventoryItem
    {
        return InventoryItem::query()->create($attributes + ['label' => 'Drill', 'status' => InventoryStatus::Stock]);
    }

    private function fakeAnswer(array $payload): void
    {
        Http::fake(['anthropic.test/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($payload)]]])]);
    }

    public function test_the_holder_and_managers_add_photos_but_only_managers_replace_or_remove_them(): void
    {
        $mine = $this->item(['status' => InventoryStatus::Assigned, 'holder_id' => $this->alice->id]);

        Livewire::actingAs($this->alice)->test(ItemSheet::class)->call('openFor', $mine->id)
            ->set('newPhotos', [UploadedFile::fake()->image('a.jpg', 900, 600)])->assertHasNoErrors();
        $photo = InventoryItemPhoto::query()->sole();
        $this->assertSame('photo', $photo->kind);
        $this->assertSame($this->alice->id, $photo->added_by);
        $this->assertSame('photo', $mine->movements()->latest('id')->first()->type->value);

        Livewire::actingAs($this->alice)->test(ItemSheet::class)->call('openFor', $mine->id)->call('removePhoto', $photo->id)->assertForbidden();
        Livewire::actingAs($this->alice)->test(ItemSheet::class)->call('openFor', $mine->id)->call('startReplace', $photo->id)->assertForbidden();

        $other = $this->item(['status' => InventoryStatus::Assigned, 'holder_id' => $this->bruno->id]);
        Livewire::actingAs($this->alice)->test(ItemSheet::class)->call('openFor', $other->id)->assertNotFound();

        $sheet = Livewire::actingAs($this->manager)->test(ItemSheet::class)->call('openFor', $mine->id)->call('startReplace', $photo->id)
            ->set('replacement', UploadedFile::fake()->image('b.jpg', 700, 500))->assertHasNoErrors();
        $this->assertSame('photo_replace', $mine->movements()->latest('id')->first()->type->value);

        $sheet->call('removePhoto', $photo->id);
        $this->assertNotNull($photo->refresh()->removed_at);
        $this->assertSame(0, $mine->photos()->count());
        Storage::disk('local')->assertExists($photo->file->path);
    }

    public function test_only_images_are_accepted_as_photos(): void
    {
        $mine = $this->item(['status' => InventoryStatus::Assigned, 'holder_id' => $this->alice->id]);

        Livewire::actingAs($this->alice)->test(ItemSheet::class)->call('openFor', $mine->id)
            ->set('newPhotos', [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])->assertHasErrors('newPhotos.0');

        $this->assertDatabaseCount('inventory_item_photos', 0);
    }

    public function test_proof_photos_are_attached_to_the_return_and_the_anomaly(): void
    {
        $item = $this->item(['status' => InventoryStatus::Assigned, 'holder_id' => $this->alice->id]);

        Livewire::actingAs($this->alice)->test(ItemSheet::class)->call('openFor', $item->id)->call('startAction', 'anomaly')
            ->set('form.comment', 'Cracked')->set('proofs', [UploadedFile::fake()->image('p.jpg')])->call('submit');

        $photo = InventoryItemPhoto::query()->sole();
        $this->assertSame('proof', $photo->kind);
        $this->assertSame([$photo->id], $item->movements()->where('type', 'anomaly')->sole()->details['photos']);
    }

    public function test_inventory_photos_are_only_visible_to_managers_and_the_holder(): void
    {
        $item = $this->item(['status' => InventoryStatus::Assigned, 'holder_id' => $this->alice->id]);
        $file = StoredFile::query()->create(['sha256' => 'x', 'path' => 'files/x.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 1]);
        Storage::disk('local')->put('files/x.jpg', 'jpeg');
        $photo = InventoryItemPhoto::query()->create(['inventory_item_id' => $item->id, 'stored_file_id' => $file->id, 'created_at' => now()]);
        $url = route('vehicle-images.show', ['type' => 'inventory', 'id' => $photo->id, 'variant' => 'full']);

        $this->actingAs($this->alice)->get($url)->assertOk();
        $this->actingAs($this->manager)->get($url)->assertOk();
        $this->actingAs($this->bruno)->get($url)->assertNotFound();

        $photo->update(['removed_at' => now()]);
        $this->actingAs($this->alice)->get($url)->assertNotFound();
        $this->actingAs($this->manager)->get($url)->assertOk();
    }

    public function test_the_analyzer_normalises_the_answer_and_matches_only_known_lines(): void
    {
        $known = $this->item(['label' => 'Splicer', 'serial' => 'AB-1']);
        $this->fakeAnswer([
            'proposals' => [
                ['label' => 'Splicer', 'category' => 'welder', 'brand' => 'Acme', 'qty' => 1, 'confidence' => 'high', 'matchItemId' => $known->reference(), 'photos' => [1, 9]],
                ['label' => 'Gloves', 'category' => 'spaceship', 'qty' => 5000, 'confidence' => 'sure', 'verify' => true, 'note' => 'Maybe two pairs', 'matchItemId' => 'MAT-9999', 'photos' => [2]],
                ['label' => '   '],
                'junk',
            ],
            'warnings' => array_fill(0, 14, 'Blurry'),
        ]);
        $files = [StoredFile::query()->create(['sha256' => 'a', 'path' => 'files/a.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 1]), StoredFile::query()->create(['sha256' => 'b', 'path' => 'files/b.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 1])];
        Storage::disk('local')->put('files/a.jpg', 'a');
        Storage::disk('local')->put('files/b.jpg', 'b');

        $result = app(InventoryPhotoAnalyzer::class)->analyse($this->manager, $files, null);

        $this->assertCount(2, $result['proposals']);
        $this->assertSame($known->reference(), $result['proposals'][0]['match']);
        $this->assertSame([1], $result['proposals'][0]['photos']);
        $this->assertNull($result['proposals'][1]['category']);
        $this->assertSame(999, $result['proposals'][1]['quantity']);
        $this->assertSame('medium', $result['proposals'][1]['confidence']);
        $this->assertNull($result['proposals'][1]['match']);
        $this->assertCount(10, $result['warnings']);

        Http::assertSent(fn (Request $request) => $request['max_tokens'] === 3500 && count($request['messages'][0]['content']) === 3 && str_contains($request['messages'][0]['content'][2]['text'], $known->reference()));
    }

    public function test_the_analyzer_refuses_without_key_photos_or_quota(): void
    {
        Http::fake();
        $file = StoredFile::query()->create(['sha256' => 'a', 'path' => 'files/a.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 1]);
        Storage::disk('local')->put('files/a.jpg', 'a');

        $reason = function (callable $call): string {
            try {
                $call();
            } catch (AiException $exception) {
                return $exception->reason;
            }

            return 'none';
        };

        $this->assertSame('invalid_image', $reason(fn () => app(InventoryPhotoAnalyzer::class)->analyse($this->manager, [], null)));

        config(['services.anthropic.key' => '']);
        $this->assertSame('not_configured', $reason(fn () => app(InventoryPhotoAnalyzer::class)->analyse($this->manager, [$file], null)));
        Http::assertNothingSent();

    }

    public function test_the_analyzer_counts_every_call_against_the_daily_quota(): void
    {
        config(['services.anthropic.quotas.inventory_identify' => ['per_user' => 1, 'global' => 5, 'per_user_hour' => 10]]);
        $file = StoredFile::query()->create(['sha256' => 'a', 'path' => 'files/a.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 1]);
        Storage::disk('local')->put('files/a.jpg', 'a');
        $this->fakeAnswer(['proposals' => [], 'warnings' => []]);

        app(InventoryPhotoAnalyzer::class)->analyse($this->manager, [$file], null);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('quota_reached');
        app(InventoryPhotoAnalyzer::class)->analyse($this->manager, [$file], null);
    }

    public function test_photos_are_analysed_previewed_corrected_and_saved_with_their_photos(): void
    {
        $existing = $this->item(['label' => 'Ladder', 'quantity' => 2]);
        $this->fakeAnswer(['proposals' => [
            ['label' => 'Fusion splicer', 'category' => 'welder', 'brand' => 'Acme', 'serial' => 'FS-1', 'qty' => 1, 'confidence' => 'high', 'photos' => [1]],
            ['label' => 'Ladder', 'category' => 'access', 'qty' => 2, 'confidence' => 'medium', 'matchItemId' => $existing->reference(), 'photos' => [2]],
            ['label' => 'Gloves', 'category' => 'safety', 'qty' => 3, 'confidence' => 'low', 'verify' => true, 'note' => 'Count uncertain', 'photos' => []],
        ], 'warnings' => ['Photo 2 is blurry']]);

        $component = Livewire::actingAs($this->manager)->test(BulkAdd::class)->call('openFor', 'photos', $this->alice->id)
            ->set('photos', [UploadedFile::fake()->image('1.jpg', 800, 600), UploadedFile::fake()->image('2.jpg', 640, 480)])
            ->call('analyse')->assertHasNoErrors()->assertSee('Photo 2 is blurry')->assertSee('Fusion splicer');

        $rows = $component->get('rows');
        $this->assertTrue($rows[0]['include']);
        $this->assertFalse($rows[1]['include']);
        $this->assertTrue($rows[2]['include']);

        $component->set('rows.2.quantity', 4)->call('save')->assertHasNoErrors()->assertDispatched('inventory-changed');

        $created = InventoryItem::query()->where('holder_id', $this->alice->id)->orderBy('id')->get();
        $this->assertSame(['Fusion splicer', 'Gloves'], $created->pluck('label')->all());
        $this->assertSame(4, $created[1]->quantity);
        $this->assertSame(InventoryStatus::ToConfirm, $created[0]->status);
        $this->assertStringStartsWith('Photos ', (string) $created[0]->source);
        $this->assertSame(1, $created[0]->photos()->count());
        $this->assertSame(2, $created[1]->photos()->count());
        $this->assertSame(2, InventoryItemPhoto::query()->whereIn('inventory_item_id', [$created[1]->id])->count());
    }

    public function test_without_ai_the_analysis_fails_cleanly_and_only_managers_can_use_it(): void
    {
        config(['services.anthropic.key' => '']);

        Livewire::actingAs($this->manager)->test(BulkAdd::class)->call('openFor', 'photos')
            ->set('photos', [UploadedFile::fake()->image('1.jpg')])->call('analyse')->assertHasErrors('form');
        $this->assertDatabaseCount('ai_usage', 0);

        Livewire::actingAs($this->alice)->test(BulkAdd::class)->call('openFor', 'photos')->assertForbidden();
        Livewire::actingAs($this->alice)->test(BulkAdd::class)->call('save')->assertForbidden();
    }

    public function test_the_list_parser_reads_markdown_and_csv(): void
    {
        $parser = app(InventoryListParser::class);

        $markdown = $parser->parse("| Désignation | Marque | Modèle | N° de série | Qté | État | Remarque | Catégorie |\n|---|---|---|---|---|---|---|---|\n| Soudeuse | Acme | FS-90 | AB-1 | 1 | Bon | série à vérifier | welder |\n| Gants | | | - | 12 | neuf | | |\n|  |  |  |  |  |  |  |  |");
        $this->assertCount(2, $markdown);
        $this->assertSame(['label' => 'Soudeuse', 'brand' => 'Acme', 'model' => 'FS-90', 'serial' => 'AB-1', 'quantity' => 1, 'condition' => 'good', 'needs_check' => true, 'uncertainty' => 'série à vérifier', 'category' => 'welder', 'vehicle' => ''], $markdown[0]);
        $this->assertSame('', $markdown[1]['serial']);
        $this->assertSame(12, $markdown[1]['quantity']);
        $this->assertSame('new', $markdown[1]['condition']);

        $semicolon = $parser->parse("Équipement;Quantité;Marque\nÉchelle;2;Acme\nPerceuse;;Bosch");
        $this->assertSame([['Échelle', 2, 'Acme'], ['Perceuse', 1, 'Bosch']], array_map(fn ($line) => [$line['label'], $line['quantity'], $line['brand']], $semicolon));

        $comma = $parser->parse("designation,serie,etat\nMètre,Z9,défectueux");
        $this->assertSame(['Mètre', 'Z9', 'defective'], [$comma[0]['label'], $comma[0]['serial'], $comma[0]['condition']]);

        $headerless = $parser->parse('Drill, Bosch, GBH, S1, 3');
        $this->assertSame(['Drill', 'Bosch', 'GBH', 'S1', 3], [$headerless[0]['label'], $headerless[0]['brand'], $headerless[0]['model'], $headerless[0]['serial'], $headerless[0]['quantity']]);

        $this->assertSame([], $parser->parse("   \n---|---\n"));
    }

    public function test_a_list_is_imported_with_the_skipped_lines_reported(): void
    {
        $this->item(['label' => 'Ladder']);

        $component = Livewire::actingAs($this->manager)->test(BulkAdd::class)->call('openFor', 'import', null)
            ->set('text', "Designation;Quantité;Série\nLadder;1;\nSaw;2;\nSplicer;1;S-1")
            ->call('parse')->assertSet('analysed', true);

        $this->assertCount(3, $component->get('rows'));
        $component->call('save')->assertHasNoErrors();

        $this->assertSame(['Ladder', 'Saw', 'Splicer'], InventoryItem::query()->orderBy('id')->pluck('label')->all());
        $this->assertSame(1, InventoryItem::query()->where('label', 'Ladder')->count());
        $this->assertSame(InventoryStatus::Stock, InventoryItem::query()->where('label', 'Saw')->first()->status);
        $this->assertSame(__('inventory.source_import'), InventoryItem::query()->where('label', 'Saw')->first()->source);

        Livewire::actingAs($this->manager)->test(BulkAdd::class)->call('openFor', 'import', null)->set('text', '|---|')->call('parse')->assertHasErrors('text');
    }
}
