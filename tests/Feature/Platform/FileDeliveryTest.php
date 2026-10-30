<?php

namespace Tests\Feature\Platform;

use App\Actions\Files\StoreUploadedFile;
use App\Enums\MediaPhase;
use App\Enums\Role;
use App\Enums\UserDocumentType;
use App\Logs\SecurityLog;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\User;
use App\Models\UserDocument;
use App\Support\FileResponse;
use App\Support\ZipDownload;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * Stored files reach the browser sandboxed (never as a page of the app), optionally through nginx, and ZIPs are
 * one at a time per person and logged (UPL-09, UPL-14).
 */
class FileDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $this->office = User::factory()->withRole(Role::DesignOffice)->create();
    }

    private function mediaFile(string $content = 'photo'): MediaFile
    {
        $folder = MediaFolder::query()->create(['parent_id' => null, 'name' => 'PM1']);
        $stored = app(StoreUploadedFile::class)->handleContents($content, 'image/jpeg', 'jpg');

        return $folder->files()->create(['phase' => MediaPhase::Before, 'file_name' => 'a.jpg', 'stored_file_id' => $stored->id, 'uploaded_at' => now()]);
    }

    private function document(User $owner, string $content, string $mime, string $extension): UserDocument
    {
        $stored = app(StoreUploadedFile::class)->handleContents($content, $mime, $extension);

        return UserDocument::query()->create(['user_id' => $owner->id, 'type' => UserDocumentType::BtpCard, 'stored_file_id' => $stored->id, 'original_name' => 'card.'.$extension]);
    }

    public function test_stored_files_are_sandboxed_and_never_sniffed(): void
    {
        $file = $this->mediaFile();

        $this->actingAs($this->office)->get(route('media-library.files', [$file, 'full']))->assertOk()
            ->assertHeader('Content-Security-Policy', FileResponse::SANDBOX_POLICY)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'image/jpeg');

        $image = $this->document($this->office, 'png-bytes', 'image/png', 'png');
        $this->actingAs($this->office)->get(route('user-documents.show', $image))->assertOk()->assertHeader('Content-Security-Policy', FileResponse::SANDBOX_POLICY);
    }

    public function test_a_pdf_opened_in_the_browser_is_not_sandboxed_so_the_browser_reader_can_show_it(): void
    {
        $pdf = $this->document($this->office, "%PDF-1.4\nbody", 'application/pdf', 'pdf');

        $response = $this->actingAs($this->office)->get(route('user-documents.show', $pdf))->assertOk();

        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_files_can_be_handed_to_nginx_when_it_is_set_up_for_it(): void
    {
        $file = $this->mediaFile();
        config(['filesystems.x_accel_redirect.enabled' => true, 'filesystems.x_accel_redirect.prefix' => '/internal-files/']);

        $response = $this->actingAs($this->office)->get(route('media-library.files', [$file, 'full']))->assertOk()
            ->assertHeader('X-Accel-Redirect', '/internal-files/'.$file->file->path)
            ->assertHeader('Content-Security-Policy', FileResponse::SANDBOX_POLICY)
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->assertSame('', $response->getContent());

        config(['filesystems.x_accel_redirect.enabled' => false]);
        $this->actingAs($this->office)->get(route('media-library.files', [$file, 'full']))->assertOk()->assertHeaderMissing('X-Accel-Redirect');
    }

    public function test_one_zip_at_a_time_per_person_with_an_explicit_message(): void
    {
        $file = $this->mediaFile();
        $lock = Cache::lock('zip-download:user:'.$this->office->id, 60);
        $this->assertTrue($lock->get());

        $this->actingAs($this->office)->post(route('media-library.zip'), ['items' => ['file:'.$file->id]])
            ->assertStatus(429)->assertSee(__('errors.zip_busy.message'));

        $colleague = User::factory()->withRole(Role::DesignOffice)->create();
        $this->actingAs($colleague)->post(route('media-library.zip'), ['items' => ['file:'.$file->id]])->assertOk()->streamedContent();

        $lock->release();
        $response = $this->actingAs($this->office)->post(route('media-library.zip'), ['items' => ['file:'.$file->id]])->assertOk();
        $response->streamedContent();

        $this->assertTrue(Cache::lock('zip-download:user:'.$this->office->id, 60)->get(), 'The lock is released once the ZIP is sent.');
    }

    public function test_every_zip_is_written_to_the_security_log(): void
    {
        config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        Log::forgetChannel(SecurityLog::CHANNEL);
        $logger = Log::channel(SecurityLog::CHANNEL);
        $this->assertInstanceOf(Logger::class, $logger);
        /** @var \Monolog\Logger $monolog */
        $monolog = $logger->getLogger();
        $handler = $monolog->getHandlers()[0];
        $this->assertInstanceOf(TestHandler::class, $handler);

        $file = $this->mediaFile('twelve bytes');
        $this->actingAs($this->office)->post(route('media-library.zip'), ['items' => ['file:'.$file->id]])->assertOk()->streamedContent();

        $records = array_values(array_filter($handler->getRecords(), fn (LogRecord $record) => $record->message === ZipDownload::LOG_EVENT));
        $this->assertCount(1, $records);
        $this->assertSame('media_library', $records[0]->context['zip']);
        $this->assertSame(1, $records[0]->context['entries']);
        $this->assertSame(12, $records[0]->context['bytes']);
        $this->assertSame($this->office->id, $records[0]->context['by_user_id']);
    }
}
