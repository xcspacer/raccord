<?php

namespace App\Livewire\DesignOfficeWorkflow;

use App\Enums\AuditAnomalyNature;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditWorkflow;
use App\Models\Audit;
use App\Models\AuditOccurrence;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Occurrences of the audits in the design office workflow (design office and supervisors), filtered by PM, state and
 * nature, newest first, with the impacted boxes still waiting for a confirmation. Each one opens its detail.
 */
class OccurrencesPage extends Component
{
    /** Most occurrences listed at once. */
    private const int LIMIT = 500;

    #[Url(as: 'audit', except: 0)]
    public int $auditId = 0;

    #[Url(except: '')]
    public string $state = '';

    #[Url(except: '')]
    public string $nature = '';

    public function mount(): void
    {
        $this->authorize('viewAny', AuditOccurrence::class);
    }

    public function render(): View
    {
        $this->authorize('viewAny', AuditOccurrence::class);
        $state = AuditOccurrenceState::tryFrom($this->state);
        $nature = AuditAnomalyNature::tryFrom($this->nature);

        $occurrences = AuditOccurrence::query()
            ->whereHas('audit', fn (Builder $audits) => $audits->where('workflow', AuditWorkflow::DesignOffice))
            ->when($this->auditId > 0, fn (Builder $query) => $query->where('audit_id', $this->auditId))
            ->when($state !== null, fn (Builder $query) => $query->where('state', $state))
            ->when($nature !== null, fn (Builder $query) => $query->where('nature', $nature))
            ->with(['audit', 'box', 'type'])
            ->withCount(['boxLinks as proposed_count' => fn (Builder $links) => $links->whereNull('confirmed_at')->whereNull('excluded_at')])
            ->withCount(['boxLinks as impacted_count' => fn (Builder $links) => $links->where('is_origin', false)->whereNotNull('confirmed_at')->whereNull('excluded_at')])
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return view('livewire.design-office-workflow.occurrences-page', [
            'audits' => $this->audits(),
            'occurrences' => $occurrences,
            'states' => AuditOccurrenceState::cases(),
            'natures' => AuditAnomalyNature::cases(),
            'isTruncated' => $occurrences->count() === self::LIMIT,
        ])->layout('components.layouts.app', ['title' => __('navigation.items.design_office_occurrences.label'), 'page' => 'design-office']);
    }

    /**
     * Audits in the design office workflow that have occurrences.
     *
     * @return Collection<int, Audit>
     */
    private function audits(): Collection
    {
        return Audit::query()->where('workflow', AuditWorkflow::DesignOffice)->whereHas('occurrences')->orderBy('cabinet_key')->get(['id', 'cabinet_key']);
    }
}
