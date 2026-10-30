<?php

namespace App\Livewire\Inventory;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Page « Gestion du matériel » of the managers: the whole inventory, or the file of one person.
 */
class Page extends Component
{
    public ?User $person = null;

    public function mount(?User $person = null): void
    {
        $this->authorize('viewAny', InventoryItem::class);

        abort_if($person !== null && $person->hasRole('design_office'), 404);

        $this->person = $person;
    }

    public function render(): View
    {
        return view('livewire.inventory.page')->layout('components.layouts.app', ['title' => $this->person === null ? __('inventory.title') : __('inventory.title_user', ['name' => $this->person->name]), 'page' => 'equipment']);
    }
}
