@can('viewAny', [\App\Models\AuditDocumentVersion::class, $audit])
    <livewire:design-office-workflow.documents-panel :audit-id="$audit->id" :key="'documents-'.$audit->id" />
@endcan
