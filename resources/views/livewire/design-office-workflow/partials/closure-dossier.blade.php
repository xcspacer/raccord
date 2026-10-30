@can('viewAny', [\App\Models\AuditDossierVersion::class, $audit])
    <livewire:design-office-workflow.dossier-panel :audit-id="$audit->id" :key="'dossier-'.$audit->id" />
@endcan
