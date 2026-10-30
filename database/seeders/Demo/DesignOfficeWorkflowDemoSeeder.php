<?php

namespace Database\Seeders\Demo;

use App\Audit\AuditOperations;
use App\Audit\AuditPhotoStore;
use App\Audit\OpenAudit;
use App\DesignOfficeWorkflow\BoxPositions;
use App\DesignOfficeWorkflow\BoxTeam;
use App\DesignOfficeWorkflow\DocumentVersions;
use App\DesignOfficeWorkflow\ImpactedBoxes;
use App\DesignOfficeWorkflow\OccurrenceLifecycle;
use App\DesignOfficeWorkflow\RequiredFolders;
use App\DesignOfficeWorkflow\ReviewQueue;
use App\DesignOfficeWorkflow\SubmitInspection;
use App\DesignOfficeWorkflow\SynopticCorrections;
use App\Enums\AuditDocumentKind;
use App\Enums\AuditFolder;
use App\Enums\Module;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditAnomalyType;
use App\Models\User;
use App\Models\WorkSite;
use App\Settings\ModuleSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Invented cabinet in the design office workflow: the module switched on, the design office user available, three
 * boxes, one started by a technician with a partner and submitted with written justifications for its missing
 * photos, so the queue and the decision screen have something to show.
 */
class DesignOfficeWorkflowDemoSeeder extends Seeder
{
    private const string CABINET = 'PM303';

    public function run(): void
    {
        $agency = Agency::query()->where('planning_mode', PlanningMode::Daily)->orderBy('id')->first();
        $manager = User::query()->where('username', 'supervisor')->first();
        $office = User::query()->where('username', 'office')->first();
        $lead = User::query()->where('username', 'tech3')->first();
        $partner = User::query()->where('username', 'tech4')->first();

        if (Audit::query()->where('cabinet_key', self::CABINET)->exists() || $agency === null || $manager === null || $office === null || $lead === null || $partner === null) {
            return;
        }

        $modules = app(ModuleSettings::class);
        $modules->enabled = array_values(array_unique([...$modules->enabled, Module::DesignOfficeWorkflow->value]));
        $modules->save();

        $lead->update(['all_agencies' => true]);
        $partner->update(['all_agencies' => true]);
        $monday = CarbonImmutable::now(config('platform.timezone'))->startOfWeek();
        $site = WorkSite::factory()->create([
            'agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'audit_mode' => 'full', 'cabinet_ref' => self::CABINET, 'city' => 'Demo City',
            'starts_on' => $monday, 'ends_on' => $monday->addDays(4), 'lead_technician_id' => $lead->id,
        ]);
        $site->members()->attach($partner->id, ['role' => 'partner']);

        $operations = app(AuditOperations::class);
        $audit = app(OpenAudit::class)->handle($manager, self::CABINET);
        $apply = fn (User $user, array $op) => $operations->apply($user, $audit->refresh(), 'o_'.bin2hex(random_bytes(5)), $op);
        $apply($manager, ['op' => 'setup', 'sro' => 'SRO-DEMO-0303', 'mode' => 'full', 'workflow' => 'design_office']);

        foreach (['BPE-0301', 'BPE-0302', 'BPE-0303'] as $name) {
            $apply($manager, ['op' => 'box_add', 'name' => $name, 'support' => 'chamber']);
        }

        app(ReviewQueue::class)->setAvailability($office, $office, true);

        $box = $audit->refresh()->boxes()->where('name', 'BPE-0301')->firstOrFail();
        $team = app(BoxTeam::class);
        $team->claim($lead, $box);
        $team->claim($partner, $box);

        $justifications = collect(app(RequiredFolders::class)->for($box->refresh()))
            ->mapWithKeys(fn (AuditFolder $folder) => [$folder->value => 'Demo: photo could not be taken on site.'])
            ->all();
        app(SubmitInspection::class)->handle($lead, $box, 'demo_'.bin2hex(random_bytes(4)), $justifications);

        $this->positionsAndDocuments($audit->refresh(), $office, $lead, $partner);
        $this->occurrences($audit->refresh(), $partner);
    }

    /**
     * Two photos taken by the partner on the second box, an optical anomaly put on hold (with the third box proposed
     * as impacted, waiting for the design office) and a mechanical one left for phase 2.
     */
    private function occurrences(Audit $audit, User $partner): void
    {
        $box = $audit->boxes()->where('name', 'BPE-0302')->firstOrFail();
        $folder = app(RequiredFolders::class)->for($box)[0] ?? AuditFolder::Open;
        $photos = [];

        foreach ([1, 2] as $index) {
            $photos[] = (int) app(AuditPhotoStore::class)->add($partner, $audit, 'p_'.bin2hex(random_bytes(5)), UploadedFile::fake()->image('demo-'.$index.'.jpg', 640, 480), [
                'box' => $box->name, 'folder' => $folder->value, 'latitude' => 45.12124, 'longitude' => 1.50452, 'captured_at' => now()->toIso8601String(),
            ])['photo'];
        }

        $lifecycle = app(OccurrenceLifecycle::class);
        $optical = AuditAnomalyType::query()->where('code', 'broken_fibre')->firstOrFail();
        $mechanical = AuditAnomalyType::query()->where('code', 'cable_entry_sealant')->firstOrFail();
        $occurrence = $lifecycle->create($partner, $box, $optical->id, 'Demo: fibres 3 to 6 dark at the splice tray.', [$photos[0]], 'Tray 2');
        $lifecycle->putOnHold($partner, $occurrence, 'Demo: the cable is cut upstream, network work needed before any repair.');
        app(ImpactedBoxes::class)->propose($partner, $occurrence, $audit->boxes()->where('name', 'BPE-0303')->firstOrFail(), 'Demo: same cable, the same fibres are dark there.');
        $lifecycle->create($partner, $box->refresh(), $mechanical->id, 'Demo: sealant missing at the cable entry.', [$photos[1]]);
    }

    /**
     * An invented map file published by the design office (positions of the three boxes), a position taken on site,
     * a corrected position proposed by the partner and a correction of the synoptic waiting for the design office.
     */
    private function positionsAndDocuments(Audit $audit, User $office, User $lead, User $partner): void
    {
        $placemarks = collect(['BPE-0301' => [45.1203, 1.5021], 'BPE-0302' => [45.1211, 1.5043], 'BPE-0303' => [45.1226, 1.5062], 'BPE-0399' => [45.1240, 1.5080]])
            ->map(fn (array $point, string $name) => '<Placemark><name>'.$name.'</name><Point><coordinates>'.$point[1].','.$point[0].',0</coordinates></Point></Placemark>')
            ->implode('');
        $kml = '<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>'.$placemarks.'</Document></kml>';
        app(DocumentVersions::class)->publish($office, $audit, AuditDocumentKind::Map, UploadedFile::fake()->createWithContent('demo-boxes.kml', $kml));

        $second = $audit->boxes()->where('name', 'BPE-0302')->firstOrFail();
        app(AuditOperations::class)->apply($lead, $audit->refresh(), 'o_'.bin2hex(random_bytes(5)), ['op' => 'box_pos', 'box' => 'BPE-0302', 'latitude' => 45.12118, 'longitude' => 1.50441, 'accuracy' => 6]);
        app(BoxTeam::class)->claim($partner, $second);
        app(BoxPositions::class)->propose($partner, $second, 45.12124, 1.50452, 4);
        app(SynopticCorrections::class)->propose($partner, $audit, $second, 'demo_'.bin2hex(random_bytes(4)), 'Demo: the cable leaving this box goes to BPE-0303, not to the cabinet.');
    }
}
