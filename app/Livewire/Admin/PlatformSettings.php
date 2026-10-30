<?php

namespace App\Livewire\Admin;

use App\Actions\Files\UploadRules;
use App\Enums\AuditFolder;
use App\Enums\Module;
use App\Settings\CompanySettings;
use App\Settings\DesignOfficeWorkflowSettings;
use App\Settings\ModuleSettings;
use App\Settings\VehicleSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Platform settings (white label): the company identity shown on screens and documents, and the
 * modules switched on for this installation.
 */
class PlatformSettings extends Component
{
    use WithFileUploads;

    #[Validate('nullable|string|max:120')]
    public ?string $name = null;

    #[Validate('nullable|string|max:160')]
    public ?string $legalName = null;

    #[Validate('nullable|email|max:160')]
    public ?string $email = null;

    #[Validate('nullable|string|max:40')]
    public ?string $phone = null;

    #[Validate('nullable|string|max:500')]
    public ?string $address = null;

    #[Validate('nullable|string|max:120')]
    public ?string $legalIdentifier = null;

    #[Validate('nullable|url:https|max:255')]
    public ?string $website = null;

    #[Validate('nullable|string|max:120')]
    public ?string $mailFromName = null;

    #[Validate('nullable|email|max:160')]
    public ?string $mailFromAddress = null;

    #[Validate('nullable|email|max:160')]
    public ?string $expenseReportEmail = null;

    #[Validate('nullable|string|max:1000')]
    public ?string $documentFooter = null;

    #[Validate('nullable|date_format:Y-m')]
    public ?string $vehicleCycleStart = null;

    #[Validate('nullable|file|max:2048|dimensions:min_width=120,min_height=40,max_width=4000,max_height=4000')]
    public ?TemporaryUploadedFile $logo = null;

    /** @var list<string> */
    #[Locked]
    public array $enabledModules = [];

    public int $reviewDeadlineMinutes = 5;

    public int $reviewAlertMinutes = 3;

    public ?int $maxOpenRequests = null;

    /** @var list<string> */
    public array $exemptFolders = [];

    public bool $aiDraftsEnabled = false;

    public function mount(CompanySettings $company, ModuleSettings $modules, VehicleSettings $vehicle, DesignOfficeWorkflowSettings $workflow): void
    {
        $this->authorize('manage-platform');

        $this->name = $company->name;
        $this->legalName = $company->legal_name;
        $this->email = $company->email;
        $this->phone = $company->phone;
        $this->address = $company->address;
        $this->legalIdentifier = $company->legal_identifier;
        $this->website = $company->website;
        $this->mailFromName = $company->mail_from_name;
        $this->mailFromAddress = $company->mail_from_address;
        $this->expenseReportEmail = $company->expense_report_email;
        $this->documentFooter = $company->document_footer;
        $this->enabledModules = $modules->enabled;
        $this->vehicleCycleStart = $vehicle->cycle_start_month;
        $this->reviewDeadlineMinutes = intdiv($workflow->review_deadline_seconds, 60);
        $this->reviewAlertMinutes = intdiv($workflow->review_alert_seconds, 60);
        $this->maxOpenRequests = $workflow->max_open_requests_per_reviewer;
        $this->exemptFolders = $workflow->exempt_folders;
        $this->aiDraftsEnabled = $workflow->ai_drafts_enabled;
    }

    /**
     * Design office workflow rules: the deadline and the alert (in minutes, the alert before the deadline), the
     * optional cap of open requests per reviewer, the photo folders a technician may leave empty and the AI drafts of
     * occurrences (off by default).
     */
    public function saveWorkflow(DesignOfficeWorkflowSettings $workflow): void
    {
        $this->authorize('manage-platform');

        $this->validate([
            'reviewDeadlineMinutes' => ['required', 'integer', 'min:1', 'max:240'],
            'reviewAlertMinutes' => ['required', 'integer', 'min:1', 'lt:reviewDeadlineMinutes'],
            'maxOpenRequests' => ['nullable', 'integer', 'min:1', 'max:100'],
            'exemptFolders' => ['array'],
            'exemptFolders.*' => [Rule::enum(AuditFolder::class)],
            'aiDraftsEnabled' => ['boolean'],
        ]);

        $workflow->review_deadline_seconds = $this->reviewDeadlineMinutes * 60;
        $workflow->review_alert_seconds = $this->reviewAlertMinutes * 60;
        $workflow->max_open_requests_per_reviewer = $this->maxOpenRequests;
        $workflow->exempt_folders = array_values(array_unique($this->exemptFolders));
        $workflow->ai_drafts_enabled = $this->aiDraftsEnabled;
        $workflow->save();

        $this->dispatch('toast', message: __('platform_settings.saved'));
    }

    public function save(CompanySettings $company, VehicleSettings $vehicle): void
    {
        $this->authorize('manage-platform');
        $this->validate();
        $this->validate(['logo' => ['nullable', UploadRules::types(UploadRules::LOGO)]]);

        $company->name = $this->blankToNull($this->name);
        $company->legal_name = $this->blankToNull($this->legalName);
        $company->email = $this->blankToNull($this->email);
        $company->phone = $this->blankToNull($this->phone);
        $company->address = $this->blankToNull($this->address);
        $company->legal_identifier = $this->blankToNull($this->legalIdentifier);
        $company->website = $this->blankToNull($this->website);
        $company->mail_from_name = $this->blankToNull($this->mailFromName);
        $company->mail_from_address = $this->blankToNull($this->mailFromAddress);
        $company->expense_report_email = $this->blankToNull($this->expenseReportEmail);
        $company->document_footer = $this->blankToNull($this->documentFooter);

        if ($this->logo !== null) {
            $previous = $company->logo_path;
            $company->logo_path = $this->logo->store('branding', 'local');
            $this->logo = null;

            if ($previous !== null) {
                Storage::disk('local')->delete($previous);
            }
        }

        $company->save();

        $vehicle->cycle_start_month = $this->blankToNull($this->vehicleCycleStart);
        $vehicle->save();

        $this->dispatch('toast', message: __('platform_settings.saved'));
    }

    public function removeLogo(CompanySettings $company): void
    {
        $this->authorize('manage-platform');

        if ($company->logo_path !== null) {
            Storage::disk('local')->delete($company->logo_path);
            $company->logo_path = null;
            $company->save();
        }
    }

    public function toggleModule(string $module, ModuleSettings $modules): void
    {
        $this->authorize('manage-platform');

        $module = Module::from($module);
        $enabled = $modules->enabled;

        $modules->enabled = in_array($module->value, $enabled, true)
            ? array_values(array_diff($enabled, [$module->value]))
            : [...$enabled, $module->value];
        $modules->save();

        $this->enabledModules = $modules->enabled;
        $this->dispatch('toast', message: __('platform_settings.saved'));
    }

    public function render(CompanySettings $company, ModuleSettings $modules): View
    {
        return view('livewire.admin.platform-settings', [
            'logoUrl' => $company->logoUrl(),
            'workflowActive' => $modules->isEnabled(Module::DesignOfficeWorkflow),
            'folders' => AuditFolder::cases(),
            'modules' => collect(Module::cases())->map(fn (Module $module) => [
                'module' => $module,
                'switchedOn' => in_array($module->value, $this->enabledModules, true),
                'active' => $modules->isEnabled($module),
                'requires' => $module->requires(),
            ]),
        ])->layout('components.layouts.app', ['title' => __('platform_settings.title'), 'page' => 'platform-settings']);
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
