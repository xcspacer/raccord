<?php

namespace App\Livewire\Admin\Users;

use App\Actions\Users\CreateUser;
use App\Enums\Role;
use App\Livewire\Forms\CreateUserForm;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Gérer les utilisateurs": add a user, search, and one editable card per user.
 */
class UserIndex extends Component
{
    public CreateUserForm $form;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function create(CreateUser $createUser): void
    {
        $this->authorize('create', User::class);

        $this->form->username = Str::lower(trim($this->form->username));
        $this->form->validate();

        $createUser->handle([
            'name' => trim($this->form->name),
            'username' => $this->form->username,
            'role' => Role::from($this->form->role),
            'agency_id' => $this->form->agencyId === '' ? null : (int) $this->form->agencyId,
            'email' => trim($this->form->email) ?: null,
            'phone' => trim($this->form->phone) ?: null,
            'vehicle' => trim($this->form->vehicle) ?: null,
            'password' => $this->form->password,
        ]);

        $this->form->reset();
        $this->dispatch('toast', message: __('users.created'));
    }

    #[On('user-deleted')]
    public function refreshList(): void
    {
        // Re-render without the deleted user.
    }

    public function render(): View
    {
        $search = trim($this->search);

        $users = User::query()
            ->with(['roles', 'agency', 'company', 'vehicle'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('username', 'like', '%'.$search.'%')))
            ->orderBy('planning_order')
            ->orderBy('name')
            ->get();

        return view('livewire.admin.users.user-index', [
            'users' => $users,
            'roles' => Role::cases(),
            'agencies' => Agency::query()->orderBy('sort_order')->get(),
        ])->layout('components.layouts.app', ['title' => __('users.title'), 'page' => 'users']);
    }
}
