<?php

namespace App\Livewire\FibreCode;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * « Code Fibre »: fibre number ↔ tube and fibre with their colours; everything is computed in the browser.
 */
class FibreCodePage extends Component
{
    public function render(): View
    {
        return view('livewire.fibre-code.fibre-code-page')
            ->layout('components.layouts.app', ['title' => __('fibre_code.title'), 'page' => 'fibre-code']);
    }
}
