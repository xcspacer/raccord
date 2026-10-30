<?php

namespace Database\Seeders\Demo;

use App\Enums\AbsenceType;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Enums\WorkSiteStatus;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSite;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Invented planning data: two agencies (detailed and daily modes), clients, companies, work sites
 * around the current week, technicians' tasks and a few absences.
 */
class PlanningDemoSeeder extends Seeder
{
    public function run(): void
    {
        $fibreNet = Client::query()->updateOrCreate(['code' => 'FIBRENET'], ['name' => 'FibreNet Telecom', 'is_active' => true]);
        $optiLink = Client::query()->updateOrCreate(['code' => 'OPTILINK'], ['name' => 'OptiLink Networks', 'is_active' => true]);

        $north = Agency::query()->updateOrCreate(['code' => 'north'], ['name' => 'Nord', 'planning_mode' => PlanningMode::Detailed, 'daily_crew_capacity' => 4, 'sort_order' => 1]);
        $south = Agency::query()->updateOrCreate(['code' => 'south'], ['name' => 'Sud', 'planning_mode' => PlanningMode::Daily, 'default_client_id' => $optiLink->id, 'daily_crew_capacity' => 3, 'sort_order' => 2]);

        $mainCompany = Company::query()->updateOrCreate(['code' => 'DEMO'], ['name' => 'Demo Fibre SAS', 'is_default' => true]);
        $partner = Company::query()->updateOrCreate(['code' => 'PARTNER'], ['name' => 'Partner Networks Ltd']);

        $technicians = User::role(Role::Technician->value)->orderBy('username')->get();

        $technicians->each(function (User $technician, int $index) use ($north, $south, $mainCompany, $partner): void {
            $technician->update([
                'agency_id' => $index < 2 ? $north->id : $south->id,
                'company_id' => $index === 3 ? $partner->id : $mainCompany->id,
                'planning_order' => $index,
            ]);
        });

        User::query()->whereIn('username', ['admin', 'supervisor', 'office'])->update(['all_agencies' => true, 'company_id' => $mainCompany->id]);

        if (WorkSite::query()->exists()) {
            return;
        }

        $monday = Carbon::now(config('platform.timezone'))->startOfWeek();
        $cities = ['Lyon', 'Metz', 'Caen', 'Pau', 'Dijon', 'Nancy', 'Tours', 'Brest'];

        foreach ($cities as $offset => $city) {
            $agency = $offset < 4 ? $north : $south;
            $startsOn = $monday->copy()->addDays($offset * 2 - 3);

            $workSite = WorkSite::query()->create([
                'agency_id' => $agency->id,
                'client_id' => $offset % 2 === 0 ? $fibreNet->id : $optiLink->id,
                'city' => $city,
                'starts_on' => $startsOn,
                'ends_on' => $agency->is($south) ? $startsOn : $startsOn->copy()->addDays(3),
                'crew_count' => $agency->is($south) ? 1 : 2,
                'status' => [WorkSiteStatus::Confirmed, WorkSiteStatus::Forecast, WorkSiteStatus::Confirmed, WorkSiteStatus::Cancelled][$offset % 4],
                'project_type' => $agency->is($south) ? [ProjectType::Swap, ProjectType::Nro, ProjectType::Audit, ProjectType::Swap][$offset % 4] : null,
                'site_manager' => $agency->is($north) ? 'Site manager '.($offset + 1) : null,
            ]);

            $technician = $technicians->firstWhere('agency_id', $agency->id);

            if ($technician !== null && $workSite->project_type === ProjectType::Swap) {
                $workSite->update(['cabinet_ref' => 'PM5'.str_pad((string) $offset, 2, '0', STR_PAD_LEFT), 'lead_technician_id' => $technician->id]);
                $workSite->boxes()->create(['reference' => 'BPE-5'.$offset.'01', 'technician_id' => $technician->id, 'position' => 0]);
            }

            if ($technician !== null) {
                Task::query()->create([
                    'user_id' => $technician->id,
                    'date' => $startsOn,
                    'label' => $workSite->project_type->value ?? 'HO',
                    'detail' => $workSite->city,
                    'work_site_id' => $workSite->id,
                ]);
            }
        }

        if ($technician = $technicians->last()) {
            Absence::query()->create([
                'user_id' => $technician->id,
                'type' => AbsenceType::Leave,
                'starts_on' => $monday->copy()->addWeek(),
                'ends_on' => $monday->copy()->addWeek()->addDays(4),
            ]);
        }
    }
}
