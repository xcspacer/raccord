<?php

namespace App\Livewire\Audit;

use App\Audit\AuditCatalog;
use App\Enums\AuditCatalogList;
use App\Models\Audit;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The lists of articles of the reports, edited by managers as text: one article per line, « code | label ».
 */
class AuditCatalogPage extends Component
{
    /** @var array<string, string> */
    public array $texts = [];

    public function mount(AuditCatalog $catalog): void
    {
        $this->authorize('manage', Audit::class);

        foreach (AuditCatalogList::cases() as $list) {
            $this->texts[$list->value] = $catalog->toText($list);
        }
    }

    public function save(AuditCatalog $catalog): void
    {
        $this->authorize('manage', Audit::class);
        $this->resetErrorBag();
        $parsed = [];

        foreach (AuditCatalogList::cases() as $list) {
            $result = $catalog->parse((string) ($this->texts[$list->value] ?? ''));

            if ($result['error'] !== null) {
                $this->addError('texts.'.$list->value, __('audit.catalog.errors.'.$result['error'], ['line' => $result['line'], 'max' => AuditCatalog::MAX_ITEMS]));

                return;
            }

            $parsed[$list->value] = $result['items'];
        }

        foreach (AuditCatalogList::cases() as $list) {
            $catalog->replace($list, $parsed[$list->value]);
        }

        $this->dispatch('toast', message: __('audit.catalog.saved'));
    }

    public function render(): View
    {
        return view('livewire.audit.audit-catalog-page', ['lists' => AuditCatalogList::cases()])
            ->layout('components.layouts.app', ['title' => __('audit.catalog.title'), 'page' => 'audits']);
    }
}
