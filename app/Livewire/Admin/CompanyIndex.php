<?php

namespace App\Livewire\Admin;

use App\Actions\Files\StoreUploadedFile;
use App\Actions\Files\UploadRules;
use App\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * « Sociétés »: the companies the staff belong to. A company's logo replaces the platform logo on the documents its
 * technicians generate (expense report, vehicle and equipment sheets); without a logo the platform logo is kept.
 */
class CompanyIndex extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public ?TemporaryUploadedFile $logo = null;

    public function mount(): void
    {
        $this->authorize('manage-platform');
    }

    public function edit(int $id): void
    {
        $this->authorize('manage-platform');

        $company = Company::query()->findOrFail($id);

        $this->resetForm();
        $this->editingId = $company->id;
        $this->name = $company->name;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(StoreUploadedFile $files): void
    {
        $this->authorize('manage-platform');

        $this->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('companies', 'name')->ignore($this->editingId)->whereNull('deleted_at')],
            'logo' => ['nullable', ...UploadRules::document(UploadRules::LOGO, 2048), 'dimensions:min_width=120,min_height=40,max_width=4000,max_height=4000'],
        ]);

        $company = $this->editingId === null ? null : Company::query()->findOrFail($this->editingId);
        $name = trim($this->name);

        DB::transaction(function () use ($company, $name, $files): void {
            $previousLogo = $company?->logo;
            $logoId = $this->logo !== null ? $files->handle($this->logo)->id : $company?->logo_file_id;

            if ($company === null) {
                Company::query()->create(['name' => $name, 'code' => $this->uniqueCode($name), 'logo_file_id' => $logoId]);
            } else {
                $company->update(['name' => $name, 'logo_file_id' => $logoId]);
            }

            if ($this->logo !== null && $previousLogo !== null) {
                $files->release($previousLogo);
            }
        });

        $this->resetForm();
        $this->dispatch('toast', message: __('companies.saved'));
    }

    public function removeLogo(int $id, StoreUploadedFile $files): void
    {
        $this->authorize('manage-platform');

        $company = Company::query()->with('logo')->findOrFail($id);
        $logo = $company->logo;

        if ($logo === null) {
            return;
        }

        $company->update(['logo_file_id' => null]);
        $files->release($logo);
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-platform');

        $company = Company::query()->findOrFail($id);

        if ($company->users()->exists()) {
            $this->addError('delete', __('companies.in_use', ['name' => $company->name]));

            return;
        }

        $company->delete();

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        $this->dispatch('toast', message: __('companies.deleted'));
    }

    public function render(): View
    {
        $companies = Company::query()->withCount('users')->orderBy('name')->get();

        return view('livewire.admin.company-index', [
            'companies' => $companies,
            'current' => $companies->firstWhere('id', $this->editingId),
        ])->layout('components.layouts.app', ['title' => __('companies.title'), 'page' => 'companies']);
    }

    private function uniqueCode(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'company', 28, '');
        $code = $base;

        for ($suffix = 2; Company::withTrashed()->where('code', $code)->exists(); $suffix++) {
            $code = $base.'-'.$suffix;
        }

        return $code;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'logo');
        $this->resetValidation();
    }
}
