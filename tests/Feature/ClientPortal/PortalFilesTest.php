<?php

namespace Tests\Feature\ClientPortal;

use App\Audit\OpenAudit;
use App\Enums\Role;
use App\Livewire\ClientPortal\PortalPage;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditPhoto;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\StoredFile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class PortalFilesTest extends TestCase
{
    use RefreshDatabase;

    private ClientAccount $account;

    private Audit $given;

    private Audit $other;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sleep::fake();
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->withRole(Role::Administrator)->create();
        $this->given = app(OpenAudit::class)->handle($admin, 'PM1');
        $this->other = app(OpenAudit::class)->handle($admin, 'PM2');
        $client = Client::factory()->create(['code' => 'ACME']);
        $this->account = ClientAccount::factory()->create(['client_id' => $client->id, 'username' => 'jane', 'has_all_audits' => false]);
        $this->account->audits()->sync([$this->given->id]);
        $this->post(route('portal.login.store', 'acme'), ['username' => 'jane', 'password' => 'portal-password']);
    }

    private function box(Audit $audit, string $name): AuditBox
    {
        return $audit->boxes()->create(['name' => $name, 'kind' => 'bpe', 'source' => 'manager', 'support' => 'pole']);
    }

    private function file(string $content): StoredFile
    {
        $path = 'files/'.sha1($content);
        Storage::disk('local')->put($path, $content);

        return StoredFile::query()->create(['sha256' => hash('sha256', $content), 'path' => $path, 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => strlen($content)]);
    }

    private function photo(AuditBox $box, string $folder = 'closed', string $subfolder = ''): AuditPhoto
    {
        return $box->photos()->create(['audit_id' => $box->audit_id, 'folder' => $folder, 'subfolder' => $subfolder, 'stored_file_id' => $this->file(uniqid('', true))->id, 'captured_at' => now(), 'received_at' => now()]);
    }

    public function test_photos_and_pdfs_are_served_only_for_the_accounts_audits(): void
    {
        $mine = $this->box($this->given, 'BPE-1');
        $mine->update(['pdf_file_id' => $this->file('pdf')->id, 'pdf_name' => 'BPE-1 OK.pdf']);
        $foreign = $this->box($this->other, 'BPE-2');

        $this->get(route('portal.photos', ['acme', $this->photo($mine)->id, 'full']))->assertOk();
        $this->get(route('portal.photos', ['acme', $this->photo($foreign)->id, 'full']))->assertNotFound();
        $this->get(route('portal.pdf', ['acme', 'box', $mine->id, 'download' => 1]))->assertOk()->assertDownload('BPE-1 OK.pdf');
        $this->get(route('portal.pdf', ['acme', 'box', $foreign->id]))->assertNotFound();

        $mine->update(['removed_at' => now()]);
        $this->get(route('portal.pdf', ['acme', 'box', $mine->id]))->assertNotFound();
    }

    public function test_the_zip_holds_the_box_folder_tree_and_refuses_a_foreign_item(): void
    {
        $box = $this->box($this->given, 'BPE-1');
        $this->photo($box);
        $this->photo($box, 'pole', 'whole');
        $box->update(['pdf_file_id' => $this->file('pdf')->id, 'pdf_name' => 'BPE-1 OK.pdf']);

        $response = $this->post(route('portal.zip', 'acme'), ['items' => ['box:'.$box->id]]);
        $response->assertOk()->assertDownload('BPE-1.zip');

        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $names = array_map(fn (int $index) => $zip->getNameIndex($index), range(0, $zip->numFiles - 1));
        $zip->close();
        unlink($path);

        $this->assertCount(3, $names);
        $this->assertContains('PM1/BPE PM1/BPE-1/BPE-1 OK.pdf', $names);
        $this->assertCount(1, preg_grep('#^PM1/BPE PM1/BPE-1/[^/]+/[^/]+/\d{8}-\d{6}_\d+\.jpg$#', $names));

        $foreign = $this->box($this->other, 'BPE-2');
        $this->post(route('portal.zip', 'acme'), ['items' => ['box:'.$box->id, 'box:'.$foreign->id]])->assertNotFound();
        $this->post(route('portal.zip', 'acme'), ['items' => ['../etc']])->assertSessionHasErrors('items.0');
    }

    public function test_the_boxes_tab_filters_sorts_and_opens_only_boxes_of_the_current_audit(): void
    {
        $this->box($this->given, 'BPE-10');
        $this->box($this->given, 'BPE-2')->update(['status' => 'ok']);
        $foreign = $this->box($this->other, 'BPE-3');
        $this->app['auth']->forgetGuards();

        $component = Livewire::test(PortalPage::class, ['portalClient' => Client::query()->sole()])
            ->call('showTab', 'boxes')
            ->assertSeeInOrder(['BPE-2', 'BPE-10'])
            ->set('status', 'ok')->assertSee('BPE-2')->assertDontSee('BPE-10')
            ->set('status', 'all')->call('sortBy', 'name')->assertSeeInOrder(['BPE-10', 'BPE-2'])
            ->call('openBox', $foreign->id)->assertDontSee('BPE-3');

        $this->assertSame('box:'.$foreign->id, $component->get('open'));
    }
}
