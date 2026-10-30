<?php

namespace Tests\Feature\Admin\Users;

use App\Actions\Files\StoreUploadedFile;
use App\Enums\AbsenceType;
use App\Enums\Role;
use App\Enums\UserDocumentType;
use App\Livewire\Admin\Users\UserCard;
use App\Logs\SecurityLog;
use App\Models\Absence;
use App\Models\DayPunch;
use App\Models\User;
use App\Models\UserDocument;
use App\Models\Vehicle;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Tests\TestCase;
use ZipArchive;

/**
 * Right of access (RGPD-06): an administrator downloads a person's personal data once their password is confirmed.
 */
class PersonalDataExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00', 'Europe/Paris'));
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->withRole(Role::Administrator)->create();
        $this->technician = User::factory()->withRole(Role::Technician)->create(['name' => 'Tom Tech', 'username' => 'ttech', 'email' => 'tom@example.test']);
    }

    private function confirmed(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    }

    /**
     * @return array<string, string>
     */
    private function entries(string $contents): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $contents);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CHECKCONS));
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            $entries[$name] = (string) $zip->getFromName($name);
        }

        $zip->close();
        unlink($path);

        return $entries;
    }

    public function test_the_zip_holds_the_personal_data_and_the_document_files(): void
    {
        config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        Log::forgetChannel(SecurityLog::CHANNEL);

        DayPunch::query()->create(['user_id' => $this->technician->id, 'date' => '2026-11-02', 'start_at' => CarbonImmutable::parse('2026-11-02 07:58', 'Europe/Paris')->utc(), 'start_received_at' => now(), 'start_latitude' => 43.9298, 'start_longitude' => 2.148, 'start_accuracy' => 15]);
        Absence::factory()->create(['user_id' => $this->technician->id, 'type' => AbsenceType::Sick, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02']);
        Vehicle::query()->create(['user_id' => $this->technician->id, 'model' => 'Van', 'plate' => 'AB-123-CD']);
        $file = app(StoreUploadedFile::class)->handleContents('licence scan', 'application/pdf', 'pdf');
        $document = UserDocument::query()->create(['user_id' => $this->technician->id, 'type' => UserDocumentType::DrivingLicence, 'stored_file_id' => $file->id, 'original_name' => 'licence.pdf']);

        $response = $this->confirmed()->actingAs($this->admin)->get(route('users.personal-data', $this->technician));

        $response->assertOk()->assertDownload('personal-data-ttech-2026-11-04.zip');
        $entries = $this->entries($response->streamedContent());

        $this->assertEqualsCanonicalizing(
            ['profile.json', 'day_punches.json', 'absences.json', 'expense_reports.json', 'expense_lines_in_progress.json', 'billing_expenses.json', 'personal_documents.json', 'equipment.json', 'vehicle.json', 'change_history.json', 'documents/driving_licence-'.$document->id.'.pdf'],
            array_keys($entries),
        );

        $profile = json_decode($entries['profile.json'], true);
        $this->assertSame('Tom Tech', $profile['name']);
        $this->assertSame('tom@example.test', $profile['email']);
        $this->assertSame(Role::Technician->value, $profile['role']);
        $this->assertArrayNotHasKey('password', $profile);

        $punch = json_decode($entries['day_punches.json'], true)[0];
        $this->assertSame('2026-11-02', $punch['date']);
        $this->assertSame('43.929800', $punch['start']['latitude']);
        $this->assertSame(15, $punch['start']['accuracy_m']);
        $this->assertSame('sick', json_decode($entries['absences.json'], true)[0]['type']);
        $this->assertSame('AB-123-CD', json_decode($entries['vehicle.json'], true)[0]['plate']);
        $this->assertSame('licence.pdf', json_decode($entries['personal_documents.json'], true)[0]['original_name']);
        $this->assertSame('licence scan', $entries['documents/driving_licence-'.$document->id.'.pdf']);

        $logger = Log::channel(SecurityLog::CHANNEL);
        $this->assertInstanceOf(Logger::class, $logger);
        $monolog = $logger->getLogger();
        $this->assertInstanceOf(MonologLogger::class, $monolog);
        $handler = $monolog->getHandlers()[0];
        $this->assertInstanceOf(TestHandler::class, $handler);
        $records = array_values(array_filter($handler->getRecords(), fn (LogRecord $record) => $record->message === SecurityLog::PERSONAL_DATA_EXPORTED));
        $this->assertCount(1, $records);
        $this->assertSame($this->technician->id, $records[0]->context['user_id']);
        $this->assertSame($this->admin->id, $records[0]->context['by_user_id']);
    }

    public function test_the_change_history_holds_only_the_journal_entries_caused_by_the_person(): void
    {
        $site = WorkSite::factory()->create(['city' => 'Dijon']);
        activity('work_sites')->performedOn($site)->causedBy($this->technician)->event('updated')
            ->withChanges(['attributes' => ['city' => 'Tours'], 'old' => ['city' => 'Dijon']])->log('updated');
        activity('work_sites')->performedOn($site)->causedBy($this->admin)->event('deleted')->log('deleted');

        $response = $this->confirmed()->actingAs($this->admin)->get(route('users.personal-data', $this->technician))->assertOk();
        $history = json_decode($this->entries($response->streamedContent())['change_history.json'], true);

        $this->assertCount(1, $history);
        $this->assertSame('updated', $history[0]['event']);
        $this->assertSame('work_sites', $history[0]['log']);
        $this->assertSame('WorkSite', $history[0]['subject_type']);
        $this->assertSame($site->id, $history[0]['subject_id']);
        $this->assertEquals(['attributes' => ['city' => 'Tours'], 'old' => ['city' => 'Dijon']], $history[0]['changes']);
        $this->assertSame('2026-11-04T12:00:00+01:00', $history[0]['at']);
        $this->assertArrayNotHasKey('batch', $history[0]['details']);
    }

    public function test_the_card_asks_for_the_password_before_sending_to_the_download(): void
    {
        $card = Livewire::actingAs($this->admin)->test(UserCard::class, ['user' => $this->technician])
            ->call('exportPersonalData')
            ->assertSet('pendingConfirmation', ['method' => 'exportPersonalData', 'params' => []])
            ->assertNoRedirect();

        $card->set('confirmablePassword', 'wrong-password')->call('confirmPassword')->assertHasErrors('confirmablePassword')->assertNoRedirect();

        $card->set('confirmablePassword', 'password')->call('confirmPassword')
            ->assertHasNoErrors()
            ->assertRedirect(route('users.personal-data', $this->technician));
    }

    public function test_the_download_needs_a_recent_password_confirmation(): void
    {
        $this->actingAs($this->admin)->get(route('users.personal-data', $this->technician))->assertForbidden();

        $this->withSession(['auth.password_confirmed_at' => now()->subSeconds((int) config('auth.password_timeout') + 1)->getTimestamp()])
            ->actingAs($this->admin)->get(route('users.personal-data', $this->technician))->assertForbidden();
    }

    public function test_only_administrators_export_personal_data(): void
    {
        foreach ([Role::Supervisor, Role::DesignOffice, Role::Technician] as $role) {
            $this->confirmed()->actingAs(User::factory()->withRole($role)->create())->get(route('users.personal-data', $this->technician))->assertForbidden();
        }

        $this->confirmed()->actingAs($this->technician)->get(route('users.personal-data', $this->technician))->assertForbidden();

        Livewire::actingAs(User::factory()->withRole(Role::Supervisor)->create())
            ->test(UserCard::class, ['user' => $this->technician])
            ->assertForbidden();

        auth()->logout();
        $this->get(route('users.personal-data', $this->technician))->assertRedirect(route('login'));
    }
}
