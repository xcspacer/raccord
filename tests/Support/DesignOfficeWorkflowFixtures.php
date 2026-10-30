<?php

namespace Tests\Support;

use App\Audit\BoxFolders;
use App\DesignOfficeWorkflow\BoxTeam;
use App\DesignOfficeWorkflow\DecideInspection;
use App\DesignOfficeWorkflow\SubmitInspection;
use App\Enums\AuditFolder;
use App\Enums\AuditPoleSection;
use App\Enums\Module;
use App\Enums\PlanningMode;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditDecision;
use App\Models\AuditPhoto;
use App\Models\AuditValidationRequest;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\WorkSite;
use App\Settings\ModuleSettings;
use Database\Seeders\DesignOfficeCatalogueSeeder;
use Database\Seeders\RoleSeeder;

/**
 * A cabinet in the design office workflow with its people: two technicians planned on it, two design office users
 * available, a supervisor, on a daily-mode agency, the module switched on and the catalogues seeded.
 */
trait DesignOfficeWorkflowFixtures
{
    protected Agency $agency;

    protected Audit $audit;

    protected User $technician;

    protected User $partner;

    protected User $reviewer;

    protected User $otherReviewer;

    protected User $supervisor;

    private int $sequence = 0;

    protected function setUpWorkflow(): void
    {
        $this->seed([RoleSeeder::class, DesignOfficeCatalogueSeeder::class]);
        $this->switchWorkflowModule(true);

        $this->agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->technician = $this->person(Role::Technician);
        $this->partner = $this->person(Role::Technician);
        $this->reviewer = $this->person(Role::DesignOffice, available: true);
        $this->otherReviewer = $this->person(Role::DesignOffice, available: true);
        $this->supervisor = $this->person(Role::Supervisor);
        $this->audit = $this->plannedAudit([$this->technician, $this->partner]);
    }

    protected function switchWorkflowModule(bool $enabled): void
    {
        $settings = app(ModuleSettings::class);
        $settings->enabled = array_values(array_unique($enabled ? [...$settings->enabled, Module::DesignOfficeWorkflow->value] : array_diff($settings->enabled, [Module::DesignOfficeWorkflow->value])));
        $settings->save();
    }

    protected function person(Role $role, bool $available = false): User
    {
        $user = User::factory()->withRole($role)->create(['agency_id' => $this->agency->id]);

        if ($available) {
            $user->forceFill(['is_available_for_review' => true])->save();
        }

        return $user;
    }

    /**
     * A design office audit planned on the given technicians (the first one leads).
     *
     * @param  list<User>  $technicians
     */
    protected function plannedAudit(array $technicians, bool $designOffice = true): Audit
    {
        $audit = $designOffice ? Audit::factory()->designOffice()->create() : Audit::factory()->create();
        $site = WorkSite::factory()->create([
            'agency_id' => $this->agency->id, 'project_type' => ProjectType::Audit->value, 'audit_id' => $audit->id,
            'cabinet_ref' => $audit->cabinet_key, 'lead_technician_id' => $technicians[0]->id,
        ]);

        foreach (array_slice($technicians, 1) as $member) {
            $site->members()->attach($member->id, ['role' => 'partner']);
        }

        return $audit;
    }

    protected function box(?Audit $audit = null, array $attributes = []): AuditBox
    {
        return AuditBox::factory()->create(['audit_id' => ($audit ?? $this->audit)->id, ...$attributes]);
    }

    /**
     * One photo in every folder asked for the box (a pole gets its three sections), except the skipped ones.
     *
     * @param  list<AuditFolder>  $skip
     */
    protected function fillPhotos(AuditBox $box, array $skip = []): void
    {
        $box->loadMissing('audit');

        foreach (app(BoxFolders::class)->for($box, $box->audit->effectiveMode()) as $folder) {
            if (in_array($folder, $skip, true)) {
                continue;
            }

            $subfolders = $folder === AuditFolder::Pole ? [AuditPoleSection::Whole->value, AuditPoleSection::Head->value, AuditPoleSection::Label->value] : [''];

            foreach ($subfolders as $subfolder) {
                $this->photo($box, $folder, $subfolder);
            }
        }
    }

    protected function photo(AuditBox $box, AuditFolder $folder, string $subfolder = ''): AuditPhoto
    {
        $hash = hash('sha256', 'photo-'.(++$this->sequence).'-'.$box->id);
        $file = StoredFile::query()->create(['sha256' => $hash, 'disk' => 'local', 'path' => 'files/'.$hash.'.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 1000]);

        return AuditPhoto::query()->create([
            'audit_id' => $box->audit_id, 'audit_box_id' => $box->id, 'folder' => $folder->value, 'subfolder' => $subfolder,
            'stored_file_id' => $file->id, 'captured_at' => now(), 'received_at' => now(), 'user_id' => $this->technician->id,
        ]);
    }

    /**
     * A box with all its photos, started by the given technician.
     */
    protected function readyBox(?User $technician = null, ?Audit $audit = null): AuditBox
    {
        $box = $this->box($audit);
        $this->fillPhotos($box);
        app(BoxTeam::class)->claim($technician ?? $this->technician, $box);

        return $box->refresh();
    }

    protected function submit(AuditBox $box, ?User $technician = null, ?string $operationId = null, array $justifications = []): AuditValidationRequest
    {
        return app(SubmitInspection::class)->handle($technician ?? $this->technician, $box, $operationId ?? $this->operationId(), $justifications)['request'];
    }

    protected function decide(AuditValidationRequest $request, string $decision, ?User $actor = null, ?string $reason = 'reason given', array $targets = [], array $proofs = []): AuditDecision
    {
        return app(DecideInspection::class)->handle($actor ?? $this->supervisor, $request, $decision, $reason, $targets, $proofs);
    }

    protected function operationId(): string
    {
        return 'op_'.(++$this->sequence).'_'.bin2hex(random_bytes(4));
    }
}
