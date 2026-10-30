<?php

namespace Tests\Feature\ClientPortal;

use App\Audit\AuditPdf;
use App\Audit\OpenAudit;
use App\ClientPortal\ClientRemarks;
use App\Enums\ClientRemarkTarget;
use App\Enums\Role;
use App\Livewire\ClientPortal\PortalPage;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditControlPoint;
use App\Models\AuditPresence;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\StoredFile;
use App\Models\User;
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
 * Data minimisation of the client portal: no e-mail address of the staff and no GPS position of a person (live
 * presence of a crew, position of each photo) on any portal page, in the page state, nor in the files it hands out.
 */
class PortalMinimisationTest extends TestCase
{
    use RefreshDatabase;

    /** E-mail addresses of the people working on the audit. */
    private const array EMAILS = ['thaddeus.fieldwork@example.test', 'olga.officeonly@example.test'];

    /** Live position of the crew on the box (a person's position). */
    private const array PRESENCE_GPS = ['43.92984', '2.14805'];

    /** Position recorded with the photo. */
    private const array PHOTO_GPS = ['43.92993', '2.14812'];

    private Audit $audit;

    private AuditBox $box;

    private AuditControlPoint $point;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sleep::fake();
        Http::fake(['*' => Http::response([], 500)]);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
        $this->seed(RoleSeeder::class);

        $office = User::factory()->withRole(Role::DesignOffice)->create(['name' => 'Olga Officeonly', 'email' => self::EMAILS[1]]);
        $technician = User::factory()->withRole(Role::Technician)->create(['name' => 'Thaddeus Fieldwork', 'email' => self::EMAILS[0], 'all_agencies' => true]);

        $this->audit = app(OpenAudit::class)->handle($office, 'PM1');
        $this->box = $this->audit->boxes()->create(['name' => 'BPE-1', 'kind' => 'bpe', 'source' => 'manager', 'support' => 'chamber', 'latitude' => 43.9298, 'longitude' => 2.148]);
        $this->point = $this->audit->controlPoints()->create(['name' => 'Splice room', 'subfolders' => []]);
        $this->photo($this->box, $technician);
        $this->photo($this->point, $technician);
        AuditPresence::query()->create(['audit_id' => $this->audit->id, 'user_id' => $technician->id, 'audit_box_id' => $this->box->id, 'state' => 'on_box', 'latitude' => 43.9298417, 'longitude' => 2.1480583, 'accuracy' => 12, 'since' => now()->subHour(), 'seen_at' => now()->subMinutes(2)]);

        $this->client = Client::factory()->create(['code' => 'ACME']);
        $account = ClientAccount::factory()->create(['client_id' => $this->client->id, 'username' => 'jane', 'has_all_audits' => false]);
        $account->audits()->sync([$this->audit->id]);

        $remarks = app(ClientRemarks::class);
        $remark = $remarks->create($account, $this->audit, ClientRemarkTarget::Box, $this->box->id, 'Please check the lid');
        $remarks->staffReply($office, $remark, 'Lid replaced');
        $remarks->acknowledge($office, $remark);

        app(AuditPdf::class)->forBox($this->box->refresh());

        $this->app['auth']->forgetGuards();
        $this->post(route('portal.login.store', 'acme'), ['username' => 'jane', 'password' => 'portal-password']);
        $this->app['auth']->forgetGuards();
    }

    private function photo(AuditBox|AuditControlPoint $unit, User $technician): void
    {
        $image = imagecreatetruecolor(320, 240);
        ob_start();
        imagejpeg($image);
        $content = (string) ob_get_clean();
        $path = 'files/'.sha1($content.$unit::class);
        Storage::disk('local')->put($path, $content);
        $file = StoredFile::query()->create(['sha256' => hash('sha256', $content.$unit::class), 'path' => $path, 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => strlen($content)]);

        $unit->photos()->create(['audit_id' => $this->audit->id, 'folder' => 'closed', 'subfolder' => '', 'stored_file_id' => $file->id, 'captured_at' => now()->subMinutes(30), 'latitude' => 43.9299321, 'longitude' => 2.1481234, 'user_id' => $technician->id, 'user_name' => $technician->name]);
    }

    private function assertMinimal(string $content, string $where, bool $withPhotoPositions = true): void
    {
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', $content, "An e-mail address reached the client in {$where}");

        foreach ([...self::EMAILS, ...self::PRESENCE_GPS, ...($withPhotoPositions ? self::PHOTO_GPS : [])] as $text) {
            $this->assertStringNotContainsString($text, $content, "« {$text} » reached the client in {$where}");
        }
    }

    public function test_no_portal_page_nor_its_state_shows_an_e_mail_or_a_position(): void
    {
        $this->assertMinimal((string) $this->get(route('portal.login', 'acme'))->getContent(), 'the sign-in page');
        $this->assertMinimal((string) $this->get(route('portal.home', 'acme'))->assertOk()->getContent(), 'the portal page');

        $component = Livewire::test(PortalPage::class, ['portalClient' => $this->client])->assertSee('Thaddeus');
        $this->assertMinimal($component->html(), 'the dashboard');

        $component->call('showTab', 'boxes')->assertSee('BPE-1');
        $this->assertMinimal($component->html(), 'the boxes tab');

        $component->call('openBox', $this->box->id)->assertSee(__('client_portal.drawer.zip'));
        $this->assertMinimal($component->html(), 'the box drawer');

        $component->call('openPoint', $this->point->id)->assertSee('Splice room');
        $this->assertMinimal($component->html(), 'the control point drawer');

        $component->call('showTab', 'notes')->assertSee('Lid replaced');
        $this->assertMinimal($component->html(), 'the notes tab');

        $this->assertMinimal(json_encode($component->instance()->all(), JSON_UNESCAPED_UNICODE) ?: '', 'the component state');
    }

    /**
     * The legacy portal showed the full name of the staff member who answered or acknowledged a note; the client now
     * only sees the first name and the initial of the last name (D15).
     */
    public function test_staff_who_answer_a_note_are_shown_by_first_name_and_initial_only(): void
    {
        $component = Livewire::test(PortalPage::class, ['portalClient' => $this->client])->call('showTab', 'notes')->assertSee('Lid replaced');
        $html = $component->html();

        $this->assertStringContainsString('Olga O.', $html);
        $this->assertStringContainsString(__('client_portal.notes.acknowledged', ['name' => 'Olga O.']), $html);
        $this->assertStringNotContainsString('Officeonly', $html);

        $component->call('openBox', $this->box->id);
        $this->assertStringNotContainsString('Officeonly', $component->html());
    }

    /**
     * The box PDF keeps the box location (worked out from the photos, as in the legacy report) but never an e-mail
     * address nor the live position of a person.
     */
    public function test_the_files_handed_to_the_client_hold_no_e_mail_and_no_live_position(): void
    {
        $pdf = (new Parser)->parseContent($this->get(route('portal.pdf', ['acme', 'box', $this->box->id]))->assertOk()->streamedContent())->getText();
        $this->assertMinimal($pdf, 'the box PDF', withPhotoPositions: false);

        $photo = $this->box->photos()->sole();
        $this->assertMinimal($this->get(route('portal.photos', ['acme', $photo->id, 'full']))->assertOk()->streamedContent(), 'the photo');

        $response = $this->post(route('portal.zip', 'acme'), ['items' => ['box:'.$this->box->id, 'point:'.$this->point->id]])->assertOk();
        $path = (string) tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $names = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            $names[] = $name;
            $content = (string) $zip->getFromIndex($index);

            $this->assertMinimal(str_ends_with($name, '.pdf') ? (new Parser)->parseContent($content)->getText() : $content, $name, withPhotoPositions: ! str_ends_with($name, '.pdf'));
        }

        $zip->close();
        unlink($path);

        $this->assertNotEmpty($names);
        $this->assertMinimal(implode("\n", $names), 'the ZIP file names');
    }
}
