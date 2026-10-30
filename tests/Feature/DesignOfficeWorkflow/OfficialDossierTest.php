<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\Actions\Files\StoreUploadedFile;
use App\DesignOfficeWorkflow\Dossier\DossierGenerator;
use App\DesignOfficeWorkflow\Dossier\DossierVersions;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Enums\AuditAnomalyNature;
use App\Enums\AuditConformity;
use App\Enums\AuditDocumentKind;
use App\Enums\AuditDossierFileRole;
use App\Enums\AuditDossierKind;
use App\Enums\AuditDossierStatus;
use App\Enums\AuditFolder;
use App\Enums\AuditOccurrencePhotoRole;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditPositionSource;
use App\Enums\AuditReserveKind;
use App\Enums\Role;
use App\Livewire\ClientPortal\ClientControlPage;
use App\Livewire\DesignOfficeWorkflow\DossierPanel;
use App\Models\Audit;
use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\AuditBoxPosition;
use App\Models\AuditDocumentVersion;
use App\Models\AuditDossierFile;
use App\Models\AuditDossierVersion;
use App\Models\AuditMissingProof;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrenceBox;
use App\Models\AuditOccurrencePhoto;
use App\Models\AuditPhoto;
use App\Models\AuditReserve;
use App\Models\Client;
use App\Models\StoredFile;
use App\Models\WorkSite;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use LogicException;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Official dossier of a closed design office audit (spec 13 §4.32–35): generation outside web requests, idempotent,
 * one version at a time, automatic checks, atomic publication, failure and relaunch, report / workbook / KMZ /
 * photos / MANIFEST with SHA-256, next versions with the changes, immutability, the client's language and the
 * downloads reserved to the design office and the supervisors.
 */
class OfficialDossierTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    private AuditBox $dossierBox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateDisk();
        config(['platform.dossier_min_free_megabytes' => 0]);
        $this->setUpWorkflow();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(storage_path('framework/testing/disks/official-dossier'));

        parent::tearDown();
    }

    /**
     * A fake private disk of its own: other test runs cleaning the shared fake disk never remove these files.
     */
    private function isolateDisk(): void
    {
        $root = storage_path('framework/testing/disks/official-dossier');
        (new Filesystem)->deleteDirectory($root);
        Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    }

    /**
     * A closed audit with one validated box: its photos (real files), an anomaly validated with Before / After photos,
     * an anomaly closed by an accepted reserve, a missing proof accepted, the current synoptic and an official position.
     */
    private function closedAudit(): Audit
    {
        $box = $this->readyBox();
        $request = $this->submit($box);
        $this->decide($request, 'validate');
        AuditMissingProof::query()->create([
            'audit_box_id' => $box->id, 'audit_submission_id' => $request->audit_submission_id, 'folder' => AuditFolder::Tco, 'justification' => 'no access to the chamber, flooded',
            'justified_by' => $this->technician->id, 'is_accepted' => true, 'decided_by' => $this->reviewer->id, 'decided_at' => now(), 'created_at' => now(),
        ]);
        $box->refresh();
        $photos = $box->photos()->orderBy('id')->get();

        $validated = $this->occurrence($box, AuditOccurrenceState::Validated, ['action_taken' => 'gel replaced', 'validated_by' => $this->reviewer->id, 'validated_at' => now()]);
        AuditOccurrencePhoto::query()->create(['audit_occurrence_id' => $validated->id, 'audit_photo_id' => $photos[0]->id, 'role' => AuditOccurrencePhotoRole::Before, 'linked_at' => now()]);
        AuditOccurrencePhoto::query()->create(['audit_occurrence_id' => $validated->id, 'audit_photo_id' => $photos[1]->id, 'role' => AuditOccurrencePhotoRole::After, 'linked_at' => now()]);
        $reserved = $this->occurrence($box, AuditOccurrenceState::ReserveAccepted);
        AuditReserve::query()->create(['audit_box_id' => $box->id, 'audit_occurrence_id' => $reserved->id, 'kind' => AuditReserveKind::AdministrativeClosure, 'reason' => 'owner refuses the work on the facade', 'accepted_by' => $this->supervisor->id, 'accepted_at' => now(), 'closed_by' => $this->supervisor->id, 'closed_at' => now()]);
        $box->forceFill(['conformity' => AuditConformity::ConformingWithReserve])->save();
        $this->materialise();

        AuditBoxPosition::query()->create(['audit_box_id' => $box->id, 'source' => AuditPositionSource::Official, 'latitude' => 43.6, 'longitude' => 1.44, 'approved_by' => $this->reviewer->id, 'approved_at' => now()]);
        AuditDocumentVersion::query()->create([
            'audit_id' => $this->audit->id, 'kind' => AuditDocumentKind::Synoptic, 'version_number' => 1, 'stored_file_id' => $this->storedContent('%PDF-1.4 synoptic', 'pdf')->id,
            'original_name' => 'synoptic.pdf', 'is_current' => true, 'published_by' => $this->reviewer->id, 'published_at' => now(),
        ]);

        $this->audit->forceFill(['closed_at' => now()->subMinute(), 'closed_by' => $this->supervisor->id])->save();
        $this->dossierBox = $box;

        return $this->audit->refresh();
    }

    private function occurrence(AuditBox $box, AuditOccurrenceState $state, array $attributes = []): AuditOccurrence
    {
        $occurrence = AuditOccurrence::factory()->create([
            'audit_box_id' => $box->id, 'audit_id' => $box->audit_id, 'nature' => AuditAnomalyNature::Mechanical, 'state' => $state,
            'audit_anomaly_type_id' => AuditAnomalyType::query()->where('nature', AuditAnomalyNature::Mechanical)->value('id'), ...$attributes,
        ]);
        AuditOccurrenceBox::query()->create(['audit_occurrence_id' => $occurrence->id, 'audit_box_id' => $box->id, 'is_origin' => true, 'added_at' => now(), 'confirmed_at' => now(), 'confirmed_by' => $this->reviewer->id]);

        return $occurrence;
    }

    /**
     * Writes a real image for every stored file the fixtures created without content.
     */
    private function materialise(): void
    {
        $image = imagecreatetruecolor(16, 12);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        foreach (StoredFile::query()->get() as $file) {
            if (! Storage::disk('local')->exists($file->path)) {
                Storage::disk('local')->put($file->path, $jpeg);
            }
        }
    }

    private function storedContent(string $contents, string $extension): StoredFile
    {
        return app(StoreUploadedFile::class)->handleContents($contents, 'application/octet-stream', $extension);
    }

    private function publishInitial(?Audit $audit = null): AuditDossierVersion
    {
        $version = app(DossierVersions::class)->authorizeInitial($audit ?? $this->closedAudit(), $this->supervisor);
        app(DossierGenerator::class)->generate($version->id);

        return $version->refresh();
    }

    /**
     * @return array<string, string> Contents by path.
     */
    private function unzip(TestResponse $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
        }

        $zip->close();
        unlink($path);

        return $entries;
    }

    private function reason(callable $action): string
    {
        try {
            $action();
        } catch (WorkflowException $exception) {
            return $exception->status.':'.$exception->reason;
        }

        return 'none';
    }

    public function test_the_first_version_is_generated_and_published_with_every_file_and_its_manifest(): void
    {
        $version = $this->publishInitial();

        $this->assertSame(AuditDossierStatus::Published, $version->status);
        $this->assertSame(AuditDossierKind::Initial, $version->kind);
        $this->assertSame('fr', $version->locale);
        $this->assertSame(1, $version->attempts);
        $this->assertNull($version->changes);

        $files = $version->files()->with('file')->get()->keyBy('path');
        $cabinet = $this->audit->cabinet_key;
        $this->assertSame(AuditDossierFileRole::Report, $files["Rapport_{$cabinet}.pdf"]->role);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($files["Rapport_{$cabinet}.pdf"]->file->path));
        $this->assertSame(AuditDossierFileRole::RepairsWorkbook, $files["Reprises_{$cabinet}.xlsx"]->role);
        $this->assertSame(AuditDossierFileRole::Map, $files["KMZ_{$cabinet}.kmz"]->role);
        $this->assertSame(AuditDossierFileRole::Synoptic, $files["Synoptique_{$cabinet}.pdf"]->role);
        $this->assertSame(AuditDossierFileRole::Manifest, $files['MANIFEST.json']->role);

        $boxPhotos = $files->filter(fn (AuditDossierFile $file) => $file->role === AuditDossierFileRole::BoxPhoto);
        $this->assertCount($this->dossierBox->photos()->count(), $boxPhotos);
        $this->assertTrue($boxPhotos->keys()->every(fn (string $path) => str_starts_with($path, 'Photos/'.$this->dossierBox->name.'/')));
        $occurrencePhotos = $files->filter(fn (AuditDossierFile $file) => $file->role === AuditDossierFileRole::OccurrencePhoto)->keys();
        $this->assertCount(2, $occurrencePhotos);
        $this->assertTrue($occurrencePhotos->contains(fn (string $path) => str_contains($path, '/Avant/')));
        $this->assertTrue($occurrencePhotos->contains(fn (string $path) => str_contains($path, '/Après/')));

        $manifest = json_decode((string) Storage::disk('local')->get($files['MANIFEST.json']->file->path), true);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame($files->count() - 1, count($manifest['files']));

        foreach ($manifest['files'] as $entry) {
            $this->assertSame($files[$entry['path']]->file->sha256, $entry['sha256']);
            $this->assertSame(64, strlen($entry['sha256']));
        }
    }

    public function test_the_kmz_places_each_box_at_its_official_position(): void
    {
        $version = $this->publishInitial();
        $map = $version->files()->where('role', AuditDossierFileRole::Map)->firstOrFail()->file;

        $path = tempnam(sys_get_temp_dir(), 'kmz');
        file_put_contents($path, Storage::disk('local')->get($map->path));
        $zip = new ZipArchive;
        $zip->open($path);
        $kml = (string) $zip->getFromName('doc.kml');
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('<name>'.$this->dossierBox->name.'</name>', $kml);
        $this->assertStringContainsString('<coordinates>1.4400000,43.6000000,0</coordinates>', $kml);
    }

    public function test_the_dossier_is_written_in_the_language_of_the_client_of_the_audit(): void
    {
        $client = Client::factory()->create(['locale' => 'en_GB']);
        WorkSite::query()->where('audit_id', $this->audit->id)->update(['client_id' => $client->id]);

        $version = $this->publishInitial();

        $this->assertSame('en_GB', $version->locale);
        $this->assertTrue($version->files()->where('path', 'Report_'.$this->audit->cabinet_key.'.pdf')->exists());
    }

    public function test_a_supervisor_sets_the_document_language_of_a_client(): void
    {
        $client = Client::factory()->create();
        $admin = $this->person(Role::Administrator);

        Livewire::actingAs($admin)->test(ClientControlPage::class)
            ->call('startBranding', $client->id)
            ->assertSet('documentLocale', 'fr')
            ->set('documentLocale', 'es')
            ->call('saveBranding')
            ->assertHasNoErrors();

        $this->assertSame('es', $client->refresh()->locale);
    }

    public function test_generating_twice_changes_nothing_and_only_one_version_is_prepared_at_a_time(): void
    {
        $audit = $this->closedAudit();
        $versions = app(DossierVersions::class);
        $version = $versions->authorizeInitial($audit, $this->supervisor);

        $this->assertSame('409:dossier_exists', $this->reason(fn () => $versions->authorizeInitial($audit, $this->supervisor)));
        $this->assertSame('409:dossier_no_initial', $this->reason(fn () => $versions->requestUpdate($audit, null, null)));

        app(DossierGenerator::class)->generate($version->id);
        $files = AuditDossierFile::query()->count();
        $this->assertSame(AuditDossierStatus::Published, app(DossierGenerator::class)->generate($version->id));

        $this->assertSame($files, AuditDossierFile::query()->count());
        $this->assertSame(1, $version->refresh()->attempts);

        $versions->requestUpdate($audit, 'label replaced', $this->supervisor);
        $this->assertSame('409:dossier_in_progress', $this->reason(fn () => $versions->requestUpdate($audit, 'again', $this->supervisor)));
    }

    public function test_an_open_audit_has_no_dossier(): void
    {
        $this->assertSame('409:audit_not_closed', $this->reason(fn () => app(DossierVersions::class)->authorizeInitial($this->audit, $this->supervisor)));
    }

    public function test_failed_checks_publish_nothing_and_a_supervisor_relaunches_the_version(): void
    {
        $audit = $this->closedAudit();
        $open = $this->occurrence($this->dossierBox, AuditOccurrenceState::ToRepair);
        $version = app(DossierVersions::class)->authorizeInitial($audit, $this->supervisor);

        $this->assertSame(AuditDossierStatus::Failed, app(DossierGenerator::class)->generate($version->id));
        $version->refresh();
        $this->assertSame('checks_failed', $version->error);
        $this->assertFalse(collect($version->checks)->firstWhere('code', 'open_occurrences')['ok']);
        $this->assertSame(0, $version->files()->count());

        Livewire::actingAs($this->reviewer)->test(DossierPanel::class, ['auditId' => $audit->id])
            ->assertSee(__('design_office_dossier.errors.checks_failed'))
            ->call('relaunch', $version->id)->assertForbidden();

        $open->forceFill(['state' => AuditOccurrenceState::ReserveAccepted])->save();
        Livewire::actingAs($this->supervisor)->test(DossierPanel::class, ['auditId' => $audit->id])->call('relaunch', $version->id)->assertHasNoErrors();
        $this->assertSame(AuditDossierStatus::Pending, $version->refresh()->status);

        $this->assertSame(AuditDossierStatus::Published, app(DossierGenerator::class)->generate($version->id));
        $this->assertSame(2, $version->refresh()->attempts);
        $this->assertSame('409:dossier_not_failed', $this->reason(fn () => app(DossierVersions::class)->relaunch($this->supervisor, $version)));
    }

    public function test_low_disk_space_stops_the_generation_and_is_shown(): void
    {
        $audit = $this->closedAudit();
        config(['platform.dossier_min_free_megabytes' => 1024 * 1024 * 1024]);
        $version = app(DossierVersions::class)->authorizeInitial($audit, $this->supervisor);

        $this->assertSame(AuditDossierStatus::Failed, app(DossierGenerator::class)->generate($version->id));
        $this->assertFalse(collect($version->refresh()->checks)->firstWhere('code', 'disk_space')['ok']);

        Livewire::actingAs($this->supervisor)->test(DossierPanel::class, ['auditId' => $audit->id])->assertSeeHtml('be-dossier__disk');
    }

    public function test_a_missing_photo_file_fails_the_checks(): void
    {
        $audit = $this->closedAudit();
        $photo = $this->dossierBox->photos()->with('file')->firstOrFail();
        Storage::disk('local')->delete($photo->file->path);
        $version = app(DossierVersions::class)->authorizeInitial($audit, $this->supervisor);

        $this->assertSame(AuditDossierStatus::Failed, app(DossierGenerator::class)->generate($version->id));
        $this->assertSame(1, collect($version->refresh()->checks)->firstWhere('code', 'photos')['count']);
    }

    public function test_a_validated_correction_after_publication_makes_the_next_version_with_its_changes(): void
    {
        $first = $this->publishInitial();
        $this->artisan('design-office:generate-dossiers')->assertSuccessful();
        $this->assertSame(1, AuditDossierVersion::query()->count());

        $this->travel(5)->minutes();
        $this->audit->forceFill(['closed_at' => null])->save();
        $second = $this->readyBox();
        $this->decide($this->submit($second), 'validate');
        $this->materialise();
        $this->audit->forceFill(['closed_at' => now(), 'closed_by' => $this->supervisor->id])->save();

        $this->artisan('design-office:generate-dossiers')->assertSuccessful();

        $next = AuditDossierVersion::query()->where('version_number', 2)->firstOrFail();
        $this->assertSame(AuditDossierStatus::Published, $next->status);
        $this->assertSame(AuditDossierKind::Update, $next->kind);
        $this->assertNull($next->authorized_by);
        $this->assertSame(1, $next->changes['previous']);
        $this->assertNotEmpty($next->changes['added']);
        $this->assertTrue(collect($next->changes['added'])->every(fn (string $path) => str_contains($path, $second->name)));
        $this->assertContains('Rapport_'.$this->audit->cabinet_key.'.pdf', $next->changes['replaced']);
        $this->assertSame([], $next->changes['removed']);

        $entries = $this->unzip($this->actingAs($this->supervisor)->get(route('design-office.dossiers.changes', $next))->assertOk());
        $this->assertArrayHasKey('MANIFEST.json', $entries);
        $this->assertArrayHasKey('MANIFEST_MODIFICATIONS.json', $entries);
        $this->assertSame(count($next->changes['added']) + count($next->changes['replaced']) + 2, count($entries));
        $modifications = json_decode($entries['MANIFEST_MODIFICATIONS.json'], true);
        $this->assertSame($next->changes['added'], $modifications['added']);

        $this->assertSame(AuditDossierStatus::Published, $first->refresh()->status);
    }

    public function test_the_full_zip_is_streamed_to_the_design_office_and_the_supervisors_only(): void
    {
        $version = $this->publishInitial();
        $paths = $version->files()->where('role', '!=', AuditDossierFileRole::ChangesManifest)->pluck('path')->sort()->values()->all();

        $entries = $this->unzip($this->actingAs($this->reviewer)->get(route('design-office.dossiers.full', $version))->assertOk());
        $this->assertSame($paths, collect(array_keys($entries))->sort()->values()->all());
        $this->actingAs($this->supervisor)->get(route('design-office.dossiers.full', $version))->assertOk();

        $this->actingAs($this->technician)->get(route('design-office.dossiers.full', $version))->assertForbidden();
        $this->actingAs($this->reviewer)->get(route('design-office.dossiers.changes', $version))->assertNotFound();

        $this->switchWorkflowModule(false);
        $this->actingAs($this->supervisor)->get(route('design-office.dossiers.full', $version))->assertNotFound();
    }

    public function test_a_version_not_published_has_nothing_to_download(): void
    {
        $version = app(DossierVersions::class)->authorizeInitial($this->closedAudit(), $this->supervisor);

        $this->actingAs($this->supervisor)->get(route('design-office.dossiers.full', $version))->assertNotFound();
    }

    public function test_the_panel_lists_the_versions_and_is_refused_to_technicians(): void
    {
        $version = $this->publishInitial();

        Livewire::actingAs($this->reviewer)->test(DossierPanel::class, ['auditId' => $this->audit->id])
            ->assertSee(__('design_office_dossier.panel.version', ['number' => 1]))
            ->assertSee(route('design-office.dossiers.full', $version))
            ->assertDontSee(__('design_office_dossier.panel.relaunch'));

        Livewire::actingAs($this->technician)->test(DossierPanel::class, ['auditId' => $this->audit->id])->assertForbidden();
        Livewire::actingAs($this->supervisor)->test(DossierPanel::class, ['auditId' => $this->plannedAudit([$this->technician], false)->id])->assertNotFound();
    }

    public function test_a_published_version_never_changes_nor_loses_a_file(): void
    {
        $version = $this->publishInitial();

        $this->assertThrows(fn () => $version->forceFill(['reason' => 'changed'])->save(), LogicException::class);
        $this->assertThrows(fn () => $version->files()->firstOrFail()->delete(), LogicException::class);
        $this->assertThrows(fn () => $version->delete(), LogicException::class);

        $photo = AuditPhoto::query()->with('file')->whereKey($this->dossierBox->photos()->value('id'))->firstOrFail();
        app(StoreUploadedFile::class)->release($photo->file);
        $this->assertTrue(Storage::disk('local')->exists($photo->file->path));
        $this->assertTrue(StoredFile::query()->whereKey($photo->stored_file_id)->exists());
    }

    public function test_the_scheduler_prepares_the_first_version_of_a_closed_audit_and_marks_interrupted_generations(): void
    {
        $this->closedAudit();

        $this->artisan('design-office:generate-dossiers')->assertSuccessful();

        $version = AuditDossierVersion::query()->sole();
        $this->assertSame(AuditDossierStatus::Published, $version->status);
        $this->assertSame($this->supervisor->id, $version->authorized_by);

        $other = $this->plannedAudit([$this->technician]);
        $other->forceFill(['closed_at' => now(), 'closed_by' => $this->supervisor->id])->save();
        $stuck = AuditDossierVersion::query()->create(['audit_id' => $other->id, 'version_number' => 1, 'kind' => AuditDossierKind::Initial, 'status' => AuditDossierStatus::Generating, 'started_at' => now()->subHours(4)]);

        $this->assertSame(1, app(DossierVersions::class)->sweep()['stale']);
        $this->assertSame('interrupted', $stuck->refresh()->error);
        $this->assertSame(AuditDossierStatus::Failed, $stuck->status);
    }

    public function test_nothing_is_generated_while_the_module_is_off(): void
    {
        $this->closedAudit();
        $this->switchWorkflowModule(false);

        $this->artisan('design-office:generate-dossiers')->assertSuccessful();

        $this->assertSame(0, AuditDossierVersion::query()->count());
    }
}
