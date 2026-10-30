<?php

namespace Tests\Feature\MediaLibrary;

use App\Audit\AuditDeliverables;
use App\Audit\OpenAudit;
use App\Enums\Role;
use App\Livewire\Audit\AuditPage;
use App\Livewire\MediaLibrary\AuditDeliverablesPage;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\StoredFile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class AuditDeliverablesTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    private Audit $audit;

    private AuditBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $this->office = User::factory()->withRole(Role::DesignOffice)->create();
        $this->audit = app(OpenAudit::class)->handle($this->office, 'PM9');
        $this->audit->update(['folder' => 'PM9 SRO-1', 'sro' => 'SRO-1']);
        $this->box = $this->audit->boxes()->create(['name' => 'BPE-0001', 'kind' => 'bpe', 'source' => 'manager', 'support' => 'chamber']);
        $file = $this->stored('photo');
        $this->box->photos()->create(['audit_id' => $this->audit->id, 'folder' => 'closed', 'stored_file_id' => $file->id, 'captured_at' => now(), 'user_name' => 'Alice Martin', 'received_at' => now()]);
        $this->box->update(['pdf_file_id' => $this->stored('pdf')->id, 'pdf_name' => 'BPE-0001 - OK.pdf']);
    }

    private function stored(string $content): StoredFile
    {
        $path = 'files/'.sha1($content);
        Storage::disk('local')->put($path, $content);

        return StoredFile::query()->create(['sha256' => hash('sha256', $content), 'path' => $path, 'mime_type' => 'image/jpeg', 'extension' => $content === 'pdf' ? 'pdf' : 'jpg', 'size_bytes' => strlen($content)]);
    }

    public function test_the_tree_holds_expected_empty_folders_photos_and_the_pdf(): void
    {
        $tree = app(AuditDeliverables::class)->of($this->audit->load(['boxes.photos.file', 'boxes.pdfFile', 'boxes.recheck', 'controlPoints.photos.file', 'controlPoints.pdfFile']));
        $closed = __('enums.audit_folders.closed');

        $this->assertSame('PM9 SRO-1', $tree['name']);
        $this->assertContains('PM9 SRO-1/BPE PM9/BPE-0001/'.__('enums.audit_folders.interior'), $tree['dirs']);
        $this->assertContains('PM9 SRO-1/BPE PM9/BPE-0001/BPE-0001 - OK.pdf', array_column($tree['files'], 'path'));
        $this->assertCount(1, preg_grep('#^PM9 SRO-1/BPE PM9/BPE-0001/'.preg_quote($closed, '#').'/\d{8}-\d{6}_\d+\.jpg$#', array_column($tree['files'], 'path')));
        $this->assertContains('PM9 SRO-1/PM9 SRO-1/'.$this->audit->controlPoints()->first()?->name, array_column($tree['units'], 'path'));
    }

    public function test_a_photo_with_a_stored_name_keeps_it_in_the_tree_and_the_zip_and_new_photos_are_unchanged(): void
    {
        $closed = 'PM9 SRO-1/BPE PM9/BPE-0001/'.__('enums.audit_folders.closed');
        $named = $this->box->photos()->create(['audit_id' => $this->audit->id, 'folder' => 'closed', 'stored_file_id' => $this->stored('named')->id, 'file_name' => '20260912-081530_a1b2c3.jpg', 'captured_at' => now(), 'received_at' => now()]);
        $twin = $this->box->photos()->create(['audit_id' => $this->audit->id, 'folder' => 'closed', 'stored_file_id' => $this->stored('twin')->id, 'file_name' => '20260912-081530_a1b2c3.jpg', 'captured_at' => now(), 'received_at' => now()]);

        $tree = app(AuditDeliverables::class)->of($this->audit->load(['boxes.photos.file', 'boxes.pdfFile', 'boxes.recheck', 'controlPoints.photos.file', 'controlPoints.pdfFile']));
        $paths = array_column($tree['files'], 'path');

        $this->assertContains($closed.'/20260912-081530_a1b2c3.jpg', $paths);
        $this->assertContains($closed.'/'.$twin->id.'_20260912-081530_a1b2c3.jpg', $paths);
        $this->assertCount(1, preg_grep('#^'.preg_quote($closed, '#').'/\d{8}-\d{6}_\d+\.jpg$#', $paths));
        $this->assertNotContains($closed.'/'.$named->received_at->utc()->format('Ymd-His').'_'.$named->id.'.jpg', $paths);

        $response = $this->actingAs($this->office)->post(route('media-library.audits.zip'), ['items' => ['PM9 SRO-1']]);
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $response->assertOk()->streamedContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $index) => (string) $zip->getNameIndex($index));
        $this->assertSame('named', $zip->getFromName((string) $names->first(fn (string $name) => str_ends_with($name, '/20260912-081530_a1b2c3.jpg') && ! str_contains($name, '_20260912'))));
        $this->assertTrue($names->contains(fn (string $name) => str_ends_with($name, '/'.$twin->id.'_20260912-081530_a1b2c3.jpg')));
        $zip->close();
        unlink($path);
    }

    public function test_activity_lists_units_with_files_and_folders_navigate_down_to_a_box(): void
    {
        Livewire::actingAs($this->office)->test(AuditDeliverablesPage::class)
            ->call('showTab', 'activity')->call('choosePeriod', 'today')
            ->assertSee('BPE-0001')->assertSee('Alice Martin')
            ->call('chooseType', 'pbo')->assertDontSee('BPE-0001')
            ->call('showTab', 'folders')->assertSee('PM9 SRO-1')
            ->call('go', 'PM9 SRO-1/BPE PM9/BPE-0001')->assertSee('BPE-0001 - OK.pdf')
            ->call('go', '../../etc')->assertSet('path', '');
    }

    public function test_files_and_zips_are_served_from_the_tree_only_to_media_library_users(): void
    {
        $pdf = 'PM9 SRO-1/BPE PM9/BPE-0001/BPE-0001 - OK.pdf';

        $this->actingAs($this->office)->get(route('media-library.audits.file', ['path' => $pdf]))->assertOk();
        $this->actingAs($this->office)->get(route('media-library.audits.file', ['path' => 'PM9 SRO-1/../secret']))->assertNotFound();
        $this->actingAs(User::factory()->withRole(Role::Technician)->create())->get(route('media-library.audits.file', ['path' => $pdf]))->assertForbidden();

        $response = $this->actingAs($this->office)->post(route('media-library.audits.zip'), ['items' => ['PM9 SRO-1', 'PM9 SRO-1/BPE PM9']]);
        $response->assertOk()->assertDownload('PM9 SRO-1.zip');

        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertSame(2, $zip->numFiles);
        $zip->close();
        unlink($path);
    }

    public function test_the_announced_size_of_a_zip_is_exactly_what_is_sent_and_entries_use_zip64(): void
    {
        $response = $this->actingAs($this->office)->post(route('media-library.audits.zip'), ['items' => ['PM9 SRO-1']]);
        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertSame((string) strlen($content), $response->headers->get('Content-Length'));
        $this->assertSame("PK\x03\x04", substr($content, 0, 4));
        $this->assertSame(45, unpack('v', substr($content, 4, 2))[1]);
        $nameLength = unpack('v', substr($content, 26, 2))[1];
        $this->assertSame(1, unpack('v', substr($content, 30 + $nameLength, 2))[1]);

        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $content);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CHECKCONS));
        $this->assertSame('photo', $zip->getFromName(collect(range(0, $zip->numFiles - 1))->map(fn (int $index) => (string) $zip->getNameIndex($index))->first(fn (string $name) => str_ends_with($name, '.jpg'))));
        $zip->close();
        unlink($path);
    }

    public function test_a_file_missing_from_the_disk_is_left_out_of_the_zip(): void
    {
        Storage::disk('local')->delete('files/'.sha1('photo'));

        $response = $this->actingAs($this->office)->post(route('media-library.audits.zip'), ['items' => ['PM9 SRO-1']]);
        $content = $response->assertOk()->streamedContent();

        $this->assertSame((string) strlen($content), $response->headers->get('Content-Length'));
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $content);
        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertSame(1, $zip->numFiles);
        $zip->close();
        unlink($path);
    }

    public function test_the_zip_of_the_plans_after_works_is_not_found_without_plans(): void
    {
        $plans = 'PM9 SRO-1/'.__('audit.tree.plans_folder', ['cabinet' => 'PM9']);

        $this->actingAs($this->office)->post(route('media-library.audits.zip'), ['items' => [$plans]])->assertNotFound();

        $this->box->recheck()->create(['recnok_file_id' => $this->stored('PK recnok')->id, 'recnok_name' => 'BPE-0001_RECNOK.xlsx', 'saved_at' => now(), 'saved_by_name' => 'Alice Martin']);

        $response = $this->actingAs($this->office)->post(route('media-library.audits.zip'), ['items' => [$plans]]);
        $response->assertOk()->assertDownload(basename($plans).'.zip');
        $this->assertStringContainsString('BPE-0001_RECNOK.xlsx', $response->streamedContent());
    }

    public function test_the_audit_page_offers_the_plans_after_works_to_managers_once_a_plan_is_saved(): void
    {
        $label = __('audit.toolbar.plans_after_works', ['count' => 1]);

        Livewire::actingAs($this->office)->test(AuditPage::class, ['cabinet' => 'PM9'])->assertDontSee($label);

        $this->box->recheck()->create(['recnok_file_id' => $this->stored('PK recnok')->id, 'recnok_name' => 'BPE-0001_RECNOK.xlsx', 'saved_at' => now(), 'saved_by_name' => 'Alice Martin']);

        Livewire::actingAs($this->office)->test(AuditPage::class, ['cabinet' => 'PM9'])
            ->assertSee($label)
            ->assertSeeHtml('value="PM9 SRO-1/'.e(__('audit.tree.plans_folder', ['cabinet' => 'PM9'])).'"');
        Livewire::actingAs(User::factory()->withRole(Role::Technician)->create())->test(AuditPage::class, ['cabinet' => 'PM9'])->assertDontSee($label);
    }
}
