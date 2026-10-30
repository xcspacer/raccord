<?php

namespace Tests\Feature\MediaLibrary;

use App\Actions\Files\StoreUploadedFile;
use App\Enums\MediaPhase;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Livewire\MediaLibrary\MediaLibraryPage;
use App\MediaLibrary\MediaLibrary;
use App\Models\Agency;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\StoredFile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->office = User::factory()->withRole(Role::DesignOffice)->create();
    }

    private function folder(string $name, ?MediaFolder $parent = null): MediaFolder
    {
        return MediaFolder::query()->create(['parent_id' => $parent?->id, 'name' => $name]);
    }

    private function file(MediaFolder $folder, string $name, string $content, MediaPhase $phase = MediaPhase::Before): MediaFile
    {
        $stored = app(StoreUploadedFile::class)->handleContents($content, 'image/jpeg', 'jpg');

        return $folder->files()->create(['phase' => $phase, 'file_name' => $name, 'stored_file_id' => $stored->id, 'uploaded_at' => now()]);
    }

    public function test_only_people_with_the_media_library_feature_get_in(): void
    {
        $this->actingAs($this->office)->get(route('media-library'))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get(route('media-library'))->assertOk();

        $technician = User::factory()->withRole(Role::Technician)->create();
        $this->actingAs($technician)->get(route('media-library'))->assertForbidden();
        Livewire::actingAs($technician)->test(MediaLibraryPage::class)->assertForbidden();

        $file = $this->file($this->folder('BPE-1', $this->folder('PM1')), 'a.jpg', 'a');
        $this->actingAs($technician)->get(route('media-library.files', [$file->id, 'full']))->assertForbidden();
        $this->actingAs($this->office)->get(route('media-library.files', [$file->id, 'full']))->assertOk();
    }

    public function test_folders_are_created_renamed_and_names_stay_unique_per_level(): void
    {
        $component = Livewire::actingAs($this->office)->test(MediaLibraryPage::class)
            ->call('startCreate')->set('newName', ' PM/10 ')->call('create')->assertHasNoErrors()
            ->call('startCreate')->set('newName', 'PM_10')->call('create')->assertHasErrors('newName')
            ->set('newName', ' . ')->call('create')->assertHasErrors('newName');

        $cabinet = MediaFolder::query()->sole();
        $this->assertSame('PM_10', $cabinet->name);

        $component->call('open', $cabinet->id)->call('startCreate')->set('newName', 'BPE-1')->call('create')
            ->call('startCreate')->set('newName', 'BPE-2')->call('create');
        $first = MediaFolder::query()->where('name', 'BPE-1')->sole();

        $component->call('startRename', $first->id)->set('renameName', 'BPE-2')->call('rename')->assertHasErrors('renameName')
            ->set('renameName', 'BPE-9')->call('rename')->assertHasNoErrors();
        $this->assertSame('BPE-9', $first->refresh()->name);
    }

    public function test_dropping_a_folder_on_another_merges_and_keeps_both_files_on_a_name_clash(): void
    {
        $source = $this->folder('PM1');
        $target = $this->folder('PM2');
        $this->file($this->folder('BPE-1', $source), 'photo.jpg', 'one');
        $this->file($this->folder('BPE-2', $source), 'other.jpg', 'two');
        $this->file($this->folder('BPE-1', $target), 'photo.jpg', 'three');

        Livewire::actingAs($this->office)->test(MediaLibraryPage::class)->call('move', $source->id, $target->id);

        $this->assertModelMissing($source);
        $this->assertSame(['BPE-1', 'BPE-2'], $target->children()->orderBy('name')->pluck('name')->all());
        $names = MediaFile::query()->whereHas('folder', fn ($query) => $query->where('name', 'BPE-1'))->pluck('file_name')->all();
        $this->assertCount(2, $names);
        $this->assertContains('photo.jpg', $names);
        $this->assertMatchesRegularExpression('/^photo_[a-z0-9]{6}\.jpg$/', collect($names)->first(fn ($name) => $name !== 'photo.jpg'));

        $this->expectException(HttpException::class);
        app(MediaLibrary::class)->merge($target, $target->children()->first());
    }

    public function test_deleting_a_folder_deletes_its_files_and_contents_used_nowhere_else(): void
    {
        $cabinet = $this->folder('PM1');
        $kept = $this->file($this->folder('BPE-1', $this->folder('PM2')), 'same.jpg', 'shared');
        $this->file($this->folder('BPE-1', $cabinet), 'same.jpg', 'shared');
        $alone = $this->file($this->folder('PM', $cabinet), 'alone.jpg', 'alone', MediaPhase::After);
        $alonePath = $alone->file->path;

        Livewire::actingAs($this->office)->test(MediaLibraryPage::class)->call('deleteFolder', $cabinet->id);

        $this->assertSame(1, MediaFile::query()->count());
        Storage::disk('local')->assertMissing($alonePath);
        Storage::disk('local')->assertExists($kept->file->path);
        $this->assertSame(1, StoredFile::query()->sole()->reference_count);
    }

    public function test_the_zip_holds_cabinet_folder_phase_and_file(): void
    {
        $cabinet = $this->folder('PM1');
        $box = $this->folder('BPE-1', $cabinet);
        $this->file($box, 'a.jpg', 'a');
        $after = $this->file($this->folder('PM', $cabinet), 'b.jpg', 'b', MediaPhase::After);

        $response = $this->actingAs($this->office)->post(route('media-library.zip'), ['items' => ['folder:'.$cabinet->id, 'file:'.$after->id]]);
        $response->assertOk()->assertDownload(__('media_library.zip_name').'.zip');

        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $names = array_map(fn (int $index) => $zip->getNameIndex($index), range(0, $zip->numFiles - 1));
        $zip->close();
        unlink($path);

        sort($names);
        $this->assertSame(['PM1/BPE-1/'.MediaPhase::Before->label().'/a.jpg', 'PM1/PM/'.MediaPhase::After->label().'/b.jpg'], $names);
        $this->actingAs($this->office)->post(route('media-library.zip'), ['items' => ['folder:999']])->assertNotFound();
    }
}
