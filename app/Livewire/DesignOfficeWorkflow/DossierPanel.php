<?php

namespace App\Livewire\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\Dossier\DossierArchive;
use App\DesignOfficeWorkflow\Dossier\DossierDiskSpace;
use App\DesignOfficeWorkflow\Dossier\DossierVersions;
use App\DesignOfficeWorkflow\WorkflowException;
use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Models\Audit;
use App\Models\AuditDossierVersion;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * « Official dossier » of the closure tab: the versions of the dossier with their state, the automatic checks of the
 * failed ones, the downloads of the published ones (full ZIP, ZIP of the changes) and the relaunch of a failed
 * generation by a supervisor. Refreshed while a version is being prepared; warns when the server's disk runs low.
 */
class DossierPanel extends Component
{
    #[Locked]
    public int $auditId;

    public function mount(int $auditId): void
    {
        $this->auditId = $auditId;
        $this->authorize('viewAny', [AuditDossierVersion::class, $this->audit()]);
    }

    public function relaunch(DossierVersions $versions, int $versionId): void
    {
        $version = AuditDossierVersion::query()->where('audit_id', $this->auditId)->findOrFail($versionId);
        $this->authorize('relaunch', $version);
        $this->resetErrorBag();

        try {
            $versions->relaunch($this->user(), $version);
        } catch (WorkflowException $exception) {
            $this->addError('dossier', $exception->translated());

            return;
        }

        $this->dispatch('toast', message: __('design_office_dossier.panel.relaunched'));
    }

    public function render(DossierDiskSpace $disk): View
    {
        $audit = $this->audit();
        $this->authorize('viewAny', [AuditDossierVersion::class, $audit]);

        $versions = AuditDossierVersion::query()->where('audit_id', $audit->id)->with(['authorizer:id,name', 'requester:id,name'])->withCount('files')
            ->orderByDesc('version_number')->get();

        return view('livewire.design-office-workflow.dossier-panel', [
            'versions' => $versions,
            'sizes' => $versions->filter(fn (AuditDossierVersion $version) => $version->isPublished())->mapWithKeys(fn (AuditDossierVersion $version) => [$version->id => $this->size(DossierArchive::fullSize($version))]),
            'inProgress' => $versions->contains(fn (AuditDossierVersion $version) => $version->status->isInProgress()),
            'isSupervisor' => WorkflowRoles::isSupervisor($this->user()),
            'diskLow' => $disk->isLow(),
            'diskFree' => $this->size((int) $disk->free()),
            'diskMinimum' => $this->size($disk->minimum()),
        ]);
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1024 ** 3
            ? __('design_office_dossier.panel.size_gb', ['value' => number_format($bytes / 1024 ** 3, 1, ',', ' ')])
            : __('design_office_dossier.panel.size_mb', ['value' => number_format($bytes / 1024 ** 2, 1, ',', ' ')]);
    }

    private function audit(): Audit
    {
        return Audit::query()->findOrFail($this->auditId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
