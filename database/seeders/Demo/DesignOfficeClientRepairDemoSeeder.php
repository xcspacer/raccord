<?php

namespace Database\Seeders\Demo;

use App\Audit\AuditOperations;
use App\Audit\AuditPhotoStore;
use App\Audit\OpenAudit;
use App\DesignOfficeWorkflow\BoxTeam;
use App\DesignOfficeWorkflow\ClientClaims;
use App\DesignOfficeWorkflow\CloseAudit;
use App\DesignOfficeWorkflow\DecideInspection;
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
use App\Models\ClientAccount;
use App\Models\User;
use App\Models\WorkSite;
use App\Settings\ModuleSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Invented cabinet of the design office workflow already closed (every closing check passed, its dossier waiting to
 * be generated), on which the client declared a new anomaly from its portal: the design office confirmed it, so it is
 * an occurrence to repair on a closed PM, planned for a repair team without reopening the audit.
 */
class DesignOfficeClientRepairDemoSeeder extends Seeder
{
    private const string CABINET = 'PM305';

    public function run(): void
    {
        $agency = Agency::query()->where('planning_mode', PlanningMode::Daily)->orderBy('id')->first();
        $manager = User::query()->where('username', 'supervisor')->first();
        $office = User::query()->where('username', 'office')->first();
        $lead = User::query()->where('username', 'tech1')->first();
        $partner = User::query()->where('username', 'tech2')->first();
        $client = ClientAccount::query()->where('username', 'client')->first();

        if (Audit::query()->where('cabinet_key', self::CABINET)->exists() || $agency === null || $manager === null || $office === null || $lead === null || $partner === null || $client === null) {
            return;
        }

        $modules = app(ModuleSettings::class);
        $modules->enabled = array_values(array_unique([...$modules->enabled, Module::DesignOfficeWorkflow->value, Module::ClientPortal->value]));
        $modules->save();

        $lead->update(['all_agencies' => true]);
        $partner->update(['all_agencies' => true]);
        $monday = CarbonImmutable::now(config('platform.timezone'))->startOfWeek();
        $site = WorkSite::factory()->create([
            'agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'audit_mode' => 'full', 'cabinet_ref' => self::CABINET, 'city' => 'Demo City',
            'starts_on' => $monday->subWeeks(2), 'ends_on' => $monday->subWeeks(2)->addDays(4), 'lead_technician_id' => $lead->id,
        ]);
        $site->members()->attach($partner->id, ['role' => 'partner']);

        $operations = app(AuditOperations::class);
        $audit = app(OpenAudit::class)->handle($manager, self::CABINET);
        $apply = fn (User $user, array $op) => $operations->apply($user, $audit->refresh(), 'o_'.bin2hex(random_bytes(5)), $op);
        $apply($manager, ['op' => 'setup', 'sro' => 'SRO-DEMO-0305', 'mode' => 'full', 'workflow' => 'design_office']);

        foreach (['BPE-0501', 'BPE-0502'] as $name) {
            $apply($manager, ['op' => 'box_add', 'name' => $name, 'support' => 'chamber']);
            $this->inspected($audit->refresh(), $name, $lead, $manager);
        }

        app(RepairPlanning::class)->openPhase($manager, $audit->refresh());
        app(CloseAudit::class)->handle($manager, $audit->refresh(), true);

        $box = $audit->boxes()->where('name', 'BPE-0502')->firstOrFail();
        $claims = app(ClientClaims::class);
        $claim = $claims->declareOccurrence($client, $box, 'Demo: the box lid is broken since the last visit, water gets in.');
        $mechanical = AuditAnomalyType::query()->where('code', 'cable_entry_sealant')->firstOrFail();
        $claims->decide($office, $claim, true, null, $mechanical->id);

        app(RepairPlanning::class)->plan($manager, $box->refresh(), $monday->addDays(2)->toDateString(), $lead->id, [$partner->id], 'Demo: client repair, bring a new lid.');
    }

    /**
     * The box started by the lead technician with one photo, its inspection submitted (missing photos justified) and
     * validated by the supervisor with every justification accepted.
     */
    private function inspected(Audit $audit, string $name, User $lead, User $manager): void
    {
        $box = $audit->boxes()->where('name', $name)->firstOrFail();
        app(BoxTeam::class)->claim($lead, $box);
        $folder = app(RequiredFolders::class)->for($box->refresh())[0] ?? AuditFolder::Open;
        app(AuditPhotoStore::class)->add($lead, $audit, 'p_'.bin2hex(random_bytes(5)), UploadedFile::fake()->image('demo-'.$name.'.jpg', 640, 480), [
            'box' => $name, 'folder' => $folder->value, 'latitude' => 45.14124, 'longitude' => 1.52452, 'captured_at' => now()->toIso8601String(),
        ]);

        $justifications = collect(app(RequiredFolders::class)->missing($box->refresh(), $box->photos()->get()))
            ->mapWithKeys(fn (AuditFolder $missing) => [$missing->value => 'Demo: photo could not be taken on site.'])
            ->all();
        $request = app(SubmitInspection::class)->handle($lead, $box, 'demo_'.bin2hex(random_bytes(4)), $justifications)['request'];
        app(DecideInspection::class)->handle($manager, $request, 'validate', null, [], array_map(fn () => true, $justifications));
    }
}
