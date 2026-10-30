<?php

namespace Tests\Feature\ClientPortal;

use App\Audit\AuditOperations;
use App\Audit\AuditPdf;
use App\Audit\OpenAudit;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Livewire\ClientPortal\PortalPage;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditBoxRecheck;
use App\Models\AuditPresence;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Smalot\PdfParser\Parser;
use Tests\TestCase;
use ZipArchive;

/**
 * What stays between the office and the crews (box messages, the comment of a box sent back, folder validations,
 * unsaved re-check drafts, crews only on their way) never reaches a client account: not on the portal, nor in its
 * PDFs and ZIPs, nor through the staff endpoints.
 */
class PortalInternalContentTest extends TestCase
{
    use RefreshDatabase;

    /** Texts and names that only the office and the crews may ever read. */
    private const array INTERNAL = ['Office message about the lid', 'Crew answer about the lid', 'Redo the splice tray', 'Draft cassette swap', 'Officeonly', 'Wayfarer'];

    private Audit $audit;

    private AuditBox $box;

    private Client $client;

    private User $office;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sleep::fake();
        Http::fake(['*' => Http::response([], 500)]);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
        $this->seed(RoleSeeder::class);

        $agency = Agency::factory()->create();
        $this->office = User::factory()->withRole(Role::DesignOffice)->create(['name' => 'Olga Officeonly']);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id, 'all_agencies' => true, 'name' => 'Thaddeus Fieldwork']);
        $this->audit = app(OpenAudit::class)->handle($this->office, 'PM1');
        WorkSite::factory()->create(['agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'cabinet_ref' => 'PM1', 'lead_technician_id' => $this->technician->id]);
        $this->box = $this->audit->boxes()->create(['name' => 'BPE-1', 'kind' => 'bpe', 'source' => 'manager', 'support' => 'chamber', 'latitude' => 43.9298, 'longitude' => 2.148]);

        $this->client = Client::factory()->create(['code' => 'ACME']);
        $account = ClientAccount::factory()->create(['client_id' => $this->client->id, 'username' => 'jane', 'has_all_audits' => false]);
        $account->audits()->sync([$this->audit->id]);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function operation(User $user, array $op): void
    {
        $this->actingAs($user);
        app(AuditOperations::class)->apply($user, $this->audit->refresh(), 'o_'.bin2hex(random_bytes(5)), $op);
    }

    private function photo(): void
    {
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));
        ob_start();
        imagejpeg($image);
        $content = (string) ob_get_clean();
        $path = 'files/'.sha1($content);
        Storage::disk('local')->put($path, $content);
        $file = StoredFile::query()->create(['sha256' => hash('sha256', $content), 'path' => $path, 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => strlen($content)]);

        $this->box->photos()->create(['audit_id' => $this->audit->id, 'folder' => 'closed', 'subfolder' => '', 'stored_file_id' => $file->id, 'captured_at' => now(), 'user_id' => $this->technician->id, 'user_name' => $this->technician->name]);
    }

    /**
     * The office and the crew talk about the box, the office validates a folder and sends the box back, a re-check
     * is left as a draft, and the box PDF is written in that state.
     */
    private function internalWork(): void
    {
        $this->photo();
        $this->operation($this->office, ['op' => 'remark', 'box' => 'BPE-1', 'text' => 'Office message about the lid']);
        $this->operation($this->technician, ['op' => 'tech_message', 'box' => 'BPE-1', 'text' => 'Crew answer about the lid']);
        $this->operation($this->office, ['op' => 'folder_validate', 'box' => 'BPE-1', 'folder' => 'closed', 'valid' => true]);
        $this->operation($this->office, ['op' => 'box_return', 'box' => 'BPE-1', 'comment' => 'Redo the splice tray']);
        AuditBoxRecheck::query()->create(['audit_box_id' => $this->box->id, 'draft' => ['changes' => [1 => ['note' => 'Draft cassette swap']]], 'has_unsaved_changes' => true]);
        app(AuditPdf::class)->forBox($this->box->refresh());

        $this->assertSame(2, $this->box->remarks()->count());
        $this->assertSame(1, $this->box->folderValidations()->count());
        $this->assertNotNull($this->box->refresh()->pdf_file_id);

        $this->app['auth']->forgetGuards();
        $this->post(route('portal.login.store', 'acme'), ['username' => 'jane', 'password' => 'portal-password']);
    }

    private function assertNothingInternal(string $content, string $where): void
    {
        foreach (self::INTERNAL as $text) {
            $this->assertStringNotContainsString($text, $content, "« {$text} » reached the client in {$where}");
        }
    }

    public function test_the_portal_pages_never_show_messages_validations_return_comments_or_drafts(): void
    {
        $this->internalWork();
        $this->app['auth']->forgetGuards();

        $component = Livewire::test(PortalPage::class, ['portalClient' => $this->client]);
        $this->assertNothingInternal($component->html(), 'the dashboard');

        $component->call('showTab', 'boxes')->assertSee('BPE-1');
        $this->assertNothingInternal($component->html(), 'the boxes tab');

        $component->call('openBox', $this->box->id)->assertSee(__('client_portal.drawer.zip'));
        $this->assertNothingInternal($component->html(), 'the box drawer');
        $this->assertNothingInternal(json_encode($component->instance()->all(), JSON_UNESCAPED_UNICODE) ?: '', 'the component state');

        $component->call('showTab', 'notes');
        $this->assertNothingInternal($component->html(), 'the notes tab');
    }

    public function test_the_box_pdf_and_the_zip_given_to_the_client_hold_nothing_internal(): void
    {
        $this->internalWork();

        $pdf = (new Parser)->parseContent($this->get(route('portal.pdf', ['acme', 'box', $this->box->id]))->assertOk()->streamedContent())->getText();
        $this->assertStringContainsString('Thaddeus Fieldwork', $pdf);
        $this->assertNothingInternal($pdf, 'the box PDF');

        $response = $this->post(route('portal.zip', 'acme'), ['items' => ['box:'.$this->box->id]])->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $names = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            $names[] = $name;

            if (str_ends_with($name, '.pdf')) {
                $this->assertNothingInternal((new Parser)->parseContent((string) $zip->getFromIndex($index))->getText(), 'the PDF of the ZIP');
            }
        }

        $zip->close();
        unlink($path);

        $this->assertCount(2, $names);
        $this->assertCount(1, preg_grep('/\.jpg$/', $names));
        $this->assertNothingInternal(implode("\n", $names), 'the ZIP file names');
    }

    public function test_a_client_session_never_opens_the_staff_endpoints_that_carry_them(): void
    {
        $this->internalWork();
        $this->assertGuest('web');
        $this->assertAuthenticated('client');

        foreach ([
            route('audits.snapshot', ['cabinet' => 'PM1']),
            route('audits.show', ['cabinet' => 'PM1']),
            route('audits.box', ['cabinet' => 'PM1', 'name' => 'BPE-1']),
            route('audits.pdf', ['cabinet' => 'PM1', 'type' => 'box', 'id' => $this->box->id]),
            route('media-library.audits'),
        ] as $url) {
            $response = $this->get($url);
            $this->assertNotSame(200, $response->getStatusCode(), $url);
            $this->assertNothingInternal((string) $response->getContent(), $url);
        }

        $this->postJson(route('audits.operations', ['cabinet' => 'PM1']), ['operation_id' => 'o_abcdef1234', 'op' => 'remark', 'box' => 'BPE-1', 'text' => 'Sneaky'])->assertUnauthorized();
        $this->assertSame(2, $this->box->remarks()->count());
    }

    public function test_a_crew_on_its_way_is_never_shown_to_the_client_only_one_on_the_box(): void
    {
        $wayfarer = User::factory()->withRole(Role::Technician)->create(['name' => 'Walter Wayfarer']);
        $second = $this->audit->boxes()->create(['name' => 'BPE-2', 'kind' => 'bpe', 'source' => 'manager', 'support' => 'chamber', 'latitude' => 43.9298, 'longitude' => 2.148]);
        $presence = ['audit_id' => $this->audit->id, 'latitude' => 43.9299, 'longitude' => 2.148, 'accuracy' => 10, 'since' => now()->subMinutes(30), 'seen_at' => now()->subMinute()];
        AuditPresence::query()->create($presence + ['user_id' => $wayfarer->id, 'audit_box_id' => $second->id, 'state' => 'en_route']);
        AuditPresence::query()->create($presence + ['user_id' => $this->technician->id, 'audit_box_id' => $this->box->id, 'state' => 'on_box']);

        $this->post(route('portal.login.store', 'acme'), ['username' => 'jane', 'password' => 'portal-password']);
        $this->app['auth']->forgetGuards();

        $html = Livewire::test(PortalPage::class, ['portalClient' => $this->client])->html();

        $this->assertStringContainsString('Thaddeus', $html);
        $this->assertStringNotContainsString('Wayfarer', $html);
        $this->assertStringNotContainsString('Walter', $html);
        $this->assertStringNotContainsString(__('enums.audit_presence_states.en_route'), $html);

        AuditPresence::query()->where('user_id', $this->technician->id)->update(['state' => 'en_route']);
        $html = Livewire::test(PortalPage::class, ['portalClient' => $this->client])->html();

        $this->assertStringNotContainsString('Thaddeus', $html);
        $this->assertStringNotContainsString(__('enums.audit_presence_states.en_route'), $html);
    }
}
