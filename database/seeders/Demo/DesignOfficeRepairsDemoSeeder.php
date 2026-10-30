<?php

namespace Database\Seeders\Demo;

use App\Audit\AuditOperations;
use App\Audit\AuditPhotoStore;
use App\Audit\OpenAudit;
use App\DesignOfficeWorkflow\BoxTeam;
use App\DesignOfficeWorkflow\DecideInspection;
use App\DesignOfficeWorkflow\OccurrenceLifecycle;
use App\DesignOfficeWorkflow\RepairPlanning;
use App\DesignOfficeWorkflow\RequiredFolders;
use App\DesignOfficeWorkflow\SubmitInspection;
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
 * Invented cabinet in phase 2 of the design office workflow: two boxes inspected and validated by the design office,
 * each with a mechanical anomaly to repair, phase 2 open, one box planned for a repair team and the other one waiting
 * in the pool, so the « Phase 2 — Reprises » screen and the phone have something to show.
 */
class DesignOfficeRepairsDemoSeeder extends Seeder
{
    private const string CABINET = 'PM304';

    public function run(): void
    {
        $agency = Agency::query()->where('planning_mode', PlanningMode::Daily)->orderBy('id')->first();
        $manager = User::query()->where('username', 'supervisor')->first();
        $lead = User::query()->where('username', 'tech1')->first();
        $partner = User::query()->where('username', 'tech2')->first();

        if (Audit::query()->where('cabinet_key', self::CABINET)->exists() || $agency === null || $manager === null || $lead === null || $partner === null) {
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
        $apply($manager, ['op' => 'setup', 'sro' => 'SRO-DEMO-0304', 'mode' => 'full', 'workflow' => 'design_office']);

        foreach (['BPE-0401', 'BPE-0402'] as $name) {
            $apply($manager, ['op' => 'box_add', 'name' => $name, 'support' => 'chamber']);
            $this->inspected($audit->refresh(), $name, $lead, $manager);
        }

        $planning = app(RepairPlanning::class);
        $planning->openPhase($manager, $audit->refresh());
        $box = $audit->boxes()->where('name', 'BPE-0401')->firstOrFail();
        $planning->plan($manager, $box, $monday->addDays(3)->toDateString(), $partner->id, [$lead->id], 'Demo: bring sealant and spare labels.');
    }

    /**
     * The box started by the lead technician, a mechanical anomaly declared with its photo, the inspection submitted
     * (missing photos justified) and validated by the supervisor.
     */
    private function inspected(Audit $audit, string $name, User $lead, User $manager): void
    {
        $box = $audit->boxes()->where('name', $name)->firstOrFail();
        app(BoxTeam::class)->claim($lead, $box);
        $folders = app(RequiredFolders::class)->for($box->refresh());
        $folder = $folders[0] ?? AuditFolder::Open;
        $photo = (int) app(AuditPhotoStore::class)->add($lead, $audit, 'p_'.bin2hex(random_bytes(5)), UploadedFile::fake()->image('demo-'.$name.'.jpg', 640, 480), [
            'box' => $name, 'folder' => $folder->value, 'latitude' => 45.13124, 'longitude' => 1.51452, 'captured_at' => now()->toIso8601String(),
        ])['photo'];
        $mechanical = AuditAnomalyType::query()->where('code', 'cable_entry_sealant')->firstOrFail();
        app(OccurrenceLifecycle::class)->create($lead, $box->refresh(), $mechanical->id, 'Demo: sealant missing at the cable entry.', [$photo]);

        $justifications = collect(app(RequiredFolders::class)->missing($box->refresh(), $box->photos()->get()))
            ->mapWithKeys(fn (AuditFolder $missing) => [$missing->value => 'Demo: photo could not be taken on site.'])
            ->all();
        $request = app(SubmitInspection::class)->handle($lead, $box, 'demo_'.bin2hex(random_bytes(4)), $justifications)['request'];
        app(DecideInspection::class)->handle($manager, $request, 'validate', null, [], array_map(fn () => true, $justifications));
    }
}
