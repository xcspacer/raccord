<?php

namespace Database\Seeders\Demo;

use App\Audit\AuditCatalog;
use App\Audit\AuditOperations;
use App\Audit\OpenAudit;
use App\Enums\AuditCatalogList;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\User;
use App\Models\WorkSite;
use App\Support\EffectiveAgency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Invented audits for the demo: one cabinet in each mode, planned on a technician, with boxes in several states.
 */
class AuditDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Audit::query()->exists()) {
            return;
        }

        $agency = Agency::query()->where('planning_mode', PlanningMode::Daily)->orderBy('id')->first();
        $manager = User::query()->where('username', 'supervisor')->first();
        $lead = User::query()->where('username', 'tech1')->first();
        $partner = User::query()->where('username', 'tech2')->first();

        if ($agency === null || $manager === null || $lead === null || $partner === null) {
            return;
        }

        $lead->update(['all_agencies' => true]);
        $partner->update(['all_agencies' => true]);
        app(EffectiveAgency::class)->switchTo($manager, $agency);
        $operations = app(AuditOperations::class);
        $this->seedCatalog(app(AuditCatalog::class));
        $monday = CarbonImmutable::now(config('platform.timezone'))->startOfWeek();

        foreach ([['PM101', 'full', 'SRO-DEMO-0101'], ['PM202', 'optical', 'SRO-DEMO-0202']] as $index => [$reference, $mode, $sro]) {
            $site = WorkSite::factory()->create([
                'agency_id' => $agency->id, 'project_type' => ProjectType::Audit->value, 'audit_mode' => $mode, 'cabinet_ref' => $reference, 'city' => 'Demo City',
                'starts_on' => $monday->addDays($index), 'ends_on' => $monday->addDays($index + 2), 'lead_technician_id' => $lead->id,
            ]);
            $site->members()->attach($partner->id, ['role' => 'partner']);

            $audit = app(OpenAudit::class)->handle($manager, $reference);
            $apply = fn (User $user, array $op) => $operations->apply($user, $audit->refresh(), 'o_'.bin2hex(random_bytes(5)), $op);

            $apply($manager, ['op' => 'setup', 'sro' => $sro, 'mode' => $mode]);

            foreach (['BPE-0001', 'BPE-0002', 'PB0003', 'JD-PBO-4'] as $name) {
                $apply($lead, ['op' => 'box_add', 'name' => $name, 'support' => $name === 'PB0003' ? 'pole' : 'chamber']);
            }

            foreach (['BPE-0001', 'BPE-0002'] as $name) {
                $apply($lead, ['op' => 'box_note', 'box' => $name, 'key' => 'before', 'text' => 'Chamber clean, cover in place.']);
                $apply($lead, ['op' => 'box_note', 'box' => $name, 'key' => 'route', 'text' => 'Nothing to report.']);
                $apply($lead, ['op' => 'box_cr', 'box' => $name, 'items' => [['id' => 'CH-LID', 'label' => 'Chamber lid replaced', 'qty' => 1]]]);
            }

            $apply($lead, ['op' => 'box_status', 'box' => 'BPE-0001', 'status' => 'ok']);
            $apply($lead, ['op' => 'box_status', 'box' => 'BPE-0002', 'status' => 'anomaly', 'reason' => 'Damaged closure']);
            $apply($manager, ['op' => 'remark', 'box' => 'BPE-0002', 'text' => 'Please add a photo of the damage.']);
        }
    }

    private function seedCatalog(AuditCatalog $catalog): void
    {
        $lists = [
            AuditCatalogList::Chamber->value => [['id' => 'CH-LID', 'label' => 'Chamber lid replaced'], ['id' => 'CH-CLEAN', 'label' => 'Chamber cleaned out'], ['id' => 'CH-DUCT', 'label' => 'Duct sealed']],
            AuditCatalogList::Pole->value => [['id' => 'PO-LABEL', 'label' => 'Pole label fitted'], ['id' => 'PO-CONDUIT', 'label' => 'Half-round conduit fitted']],
            AuditCatalogList::Cabinet->value => [['id' => 'CA-LABEL', 'label' => 'Cabinet labels renewed'], ['id' => 'CA-SEAL', 'label' => 'Cabinet seal replaced']],
        ];

        foreach (AuditCatalogList::cases() as $list) {
            if ($catalog->items($list) === []) {
                $catalog->replace($list, $lists[$list->value]);
            }
        }
    }
}
