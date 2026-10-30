<?php

namespace App\Livewire\Otdr;

use App\Settings\CompanySettings;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * « Recette OTDR »: the page hosting the acceptance tool. Every measurement is read and computed on the device; the
 * server only checks access and hands over the screen texts and the product name written in the deliverables.
 */
class OtdrPage extends Component
{
    public function render(CompanySettings $company): View
    {
        return view('livewire.otdr.otdr-page', [
            'product' => $company->displayName(),
        ])->layout('components.layouts.app', ['title' => __('otdr.title'), 'page' => 'otdr']);
    }
}
