<div class="review-queue" wire:poll.30s.visible>
    <p class="page-hint">{{ __('design_office_workflow.occurrences.hint') }}</p>

    <div class="toolbar">
        <div class="toolbar__filters">
            <select class="select-filter" wire:model.live="auditId" aria-label="{{ __('design_office_workflow.occurrences.audit') }}">
                <option value="0">{{ __('design_office_workflow.occurrences.all_audits') }}</option>
                @foreach ($audits as $audit)
                    <option value="{{ $audit->id }}">{{ $audit->cabinet_key }}</option>
                @endforeach
            </select>
            <select class="select-filter" wire:model.live="state" aria-label="{{ __('design_office_workflow.occurrences.state') }}">
                <option value="">{{ __('design_office_workflow.occurrences.all_states') }}</option>
                @foreach ($states as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            <select class="select-filter" wire:model.live="nature" aria-label="{{ __('design_office_workflow.occurrences.nature') }}">
                <option value="">{{ __('design_office_workflow.occurrences.all_natures') }}</option>
                @foreach ($natures as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </div>
        <livewire:design-office-workflow.declare-occurrence-form />
        @can('viewAny', \App\Models\AuditAnomalyType::class)
            <a class="btn btn--ghost" href="{{ route('design-office.catalogues') }}" wire:navigate>{{ __('design_office_workflow.occurrences.catalogues') }}</a>
        @endcan
    </div>

    @if ($occurrences->isEmpty())
        <div class="empty-state">{{ __('design_office_workflow.occurrences.empty') }}</div>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('design_office_workflow.occurrences.reference') }}</th>
                    <th>{{ __('design_office_workflow.occurrences.box') }}</th>
                    <th>{{ __('design_office_workflow.occurrences.type') }}</th>
                    <th>{{ __('design_office_workflow.occurrences.nature') }}</th>
                    <th>{{ __('design_office_workflow.occurrences.state') }}</th>
                    <th>{{ __('design_office_workflow.occurrences.impacted') }}</th>
                    <th>{{ __('design_office_workflow.occurrences.declared') }}</th>
                    <th>{{ __('design_office_workflow.occurrences.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($occurrences as $occurrence)
                    <tr wire:key="occurrence-{{ $occurrence->id }}">
                        <td data-label="{{ __('design_office_workflow.occurrences.reference') }}"><span class="occurrence-ref">{{ $occurrence->reference }}</span></td>
                        <td data-label="{{ __('design_office_workflow.occurrences.box') }}">{{ $occurrence->box->name }}</td>
                        <td data-label="{{ __('design_office_workflow.occurrences.type') }}">{{ $occurrence->type->displayLabel() }}</td>
                        <td data-label="{{ __('design_office_workflow.occurrences.nature') }}"><span class="review-nature review-nature--{{ $occurrence->nature->value }}">{{ $occurrence->nature->label() }}</span></td>
                        <td data-label="{{ __('design_office_workflow.occurrences.state') }}">
                            <span class="occurrence-state occurrence-state--{{ $occurrence->state->value }}">{{ $occurrence->state->label() }}</span>
                            @if ($occurrence->isPending())
                                <span class="occurrence-flag">{{ __('design_office_workflow.occurrences.on_hold') }}</span>
                            @endif
                        </td>
                        <td data-label="{{ __('design_office_workflow.occurrences.impacted') }}">
                            {{ $occurrence->impacted_count }}
                            @if ($occurrence->proposed_count > 0)
                                <span class="occurrence-flag occurrence-flag--todo">{{ trans_choice('design_office_workflow.occurrences.to_confirm', $occurrence->proposed_count, ['count' => $occurrence->proposed_count]) }}</span>
                            @endif
                        </td>
                        <td data-label="{{ __('design_office_workflow.occurrences.declared') }}">{{ $occurrence->created_at->timezone(config('platform.timezone'))->format('d/m/Y H:i') }}</td>
                        <td data-label="{{ __('design_office_workflow.occurrences.actions') }}">
                            <a class="btn btn--ghost btn--small" href="{{ route('design-office.occurrences.show', $occurrence) }}" wire:navigate>{{ __('design_office_workflow.occurrences.open') }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($isTruncated)
            <p class="page-hint">{{ __('design_office_workflow.occurrences.truncated') }}</p>
        @endif
    @endif
</div>
