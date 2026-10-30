<?php

namespace App\Livewire\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\ClientClaims;
use App\DesignOfficeWorkflow\WorkflowException;
use App\DesignOfficeWorkflow\WorkflowRoles;
use App\Enums\AuditClientClaimKind;
use App\Enums\AuditClientClaimState;
use App\Models\AuditAnomalyType;
use App\Models\AuditClientClaim;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Claims the clients raised on their portal about design office audits: disputes of a reserve (the design office
 * answers the first one, a supervisor the second) and new occurrences declared on a closed audit (confirming one makes
 * it an occurrence to repair). Each claim is answered once; the client sees the answer on its portal.
 */
class ClientClaimsPage extends Component
{
    /** Days of answered claims listed below the open ones. */
    private const int RECENT_DAYS = 30;

    #[Locked]
    public ?int $deciding = null;

    public string $verdict = '';

    public string $reason = '';

    public string $anomalyType = '';

    public function mount(): void
    {
        $this->authorize('viewAny', AuditClientClaim::class);
    }

    public function startDecision(int $claimId): void
    {
        $claim = AuditClientClaim::query()->findOrFail($claimId);
        $this->authorize('decide', $claim);

        $this->deciding = $this->deciding === $claim->id ? null : $claim->id;
        $this->reset('verdict', 'reason', 'anomalyType');
        $this->resetValidation();
    }

    public function decide(ClientClaims $claims): void
    {
        $claim = AuditClientClaim::query()->findOrFail($this->deciding);
        $this->authorize('decide', $claim);
        $this->resetValidation();

        $this->validate([
            'verdict' => ['required', 'in:accept,refuse'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'anomalyType' => ['nullable', 'integer'],
        ]);

        try {
            $claims->decide($this->user(), $claim, $this->verdict === 'accept', $this->reason, $this->anomalyType === '' ? null : (int) $this->anomalyType);
        } catch (WorkflowException $exception) {
            $this->addError('verdict', $exception->translated());

            return;
        }

        $this->reset('deciding', 'verdict', 'reason', 'anomalyType');
        $this->dispatch('toast', message: __('design_office_workflow.claims.decided'));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'verdict' => __('design_office_workflow.claims.verdict'),
            'reason' => __('design_office_workflow.claims.reason'),
            'anomalyType' => __('design_office_workflow.claims.anomaly_type'),
        ];
    }

    public function render(): View
    {
        $user = $this->user();
        $relations = ['audit:id,cabinet_key', 'box:id,name', 'reserve', 'occurrence:id,reference', 'clientAccount.client:id,name', 'decider:id,name'];

        return view('livewire.design-office-workflow.client-claims-page', [
            'isSupervisor' => WorkflowRoles::isSupervisor($user),
            'open' => AuditClientClaim::query()->whereIn('state', [AuditClientClaimState::Open, AuditClientClaimState::Escalated])->with($relations)->orderBy('id')->get(),
            'answered' => AuditClientClaim::query()->whereNotNull('decided_at')->where('decided_at', '>=', now()->subDays(self::RECENT_DAYS))->with($relations)->latest('decided_at')->limit(50)->get(),
            'anomalyTypes' => AuditAnomalyType::query()->where('is_active', true)->orderBy('position')->orderBy('id')->get(),
            'newOccurrence' => AuditClientClaimKind::NewOccurrence,
        ])->layout('components.layouts.app', ['title' => __('navigation.items.design_office_claims.label'), 'page' => 'design-office']);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
