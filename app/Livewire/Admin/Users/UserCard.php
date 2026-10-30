<?php

namespace App\Livewire\Admin\Users;

use App\Actions\Users\ChangeUserRole;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\ResetUserPassword;
use App\Actions\Users\UserRules;
use App\DesignOfficeWorkflow\ReviewQueue;
use App\Enums\Module;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Livewire\Concerns\ConfirmsPassword;
use App\Logs\SecurityLog;
use App\Models\Absence;
use App\Models\Agency;
use App\Models\AuditValidationRequest;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\Task;
use App\Models\User;
use App\Settings\CompanySettings;
use App\Settings\ModuleSettings;
use App\Support\UserSessions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One editable user on "Gérer les utilisateurs". Fields are saved as soon as they change, as in the
 * legacy page; every action is authorised and validated on the server.
 */
class UserCard extends Component
{
    use ConfirmsPassword;

    #[Locked]
    public int $userId;

    public string $role = '';

    public string $agency = '';

    public string $companyId = '';

    public string $email = '';

    public string $phone = '';

    public string $vehicleModel = '';

    public string $vehiclePlate = '';

    public string $departureDate = '';

    public string $newPassword = '';

    public function mount(User $user): void
    {
        $this->authorize('update', $user);

        $this->userId = $user->id;
        $this->fillFrom($user);
    }

    public function updated(string $property): void
    {
        $this->authorize('update', User::class);

        match ($property) {
            'role' => $this->saveRole(),
            'agency' => $this->saveAgency(),
            'companyId' => $this->saveCompany(),
            'email', 'phone' => $this->saveContact($property),
            'vehicleModel', 'vehiclePlate' => $this->saveVehicle(),
            'departureDate' => $this->askDepartureConfirmation(),
            default => null,
        };
    }

    public function saveAll(): void
    {
        $this->authorize('update', User::class);

        $this->saveRole();
        $this->saveAgency();
        $this->saveCompany();
        $this->saveContact('email');
        $this->saveContact('phone');
        $this->saveVehicle();

        $this->dispatch('toast', message: __('users.saved'));
    }

    public function confirmDeparture(string $date): void
    {
        $this->authorize('update', User::class);
        $user = $this->user();

        if ($user->is(auth()->user())) {
            throw ValidationException::withMessages(['departureDate' => __('users.errors.own_departure')]);
        }

        if ($date === '') {
            $user->update(['departure_date' => null]);
            $this->dispatch('toast', message: __('users.departure_cancelled'));
        } else {
            validator(['departureDate' => $date], ['departureDate' => ['date_format:Y-m-d']])->validate();
            app(UserRules::class)->ensureAnotherAdministratorRemains($user, 'departureDate');
            $user->update(['departure_date' => $date]);
            $this->dispatch('toast', message: __('users.departure_saved', ['name' => $user->name, 'date' => Carbon::parse($date)->translatedFormat('d M Y')]));
        }

        $this->fillFrom($user->refresh());
    }

    public function changePassword(ResetUserPassword $resetPassword): void
    {
        $this->authorize('update', User::class);
        $user = $this->user();

        $this->validate(['newPassword' => ['required', 'string', Password::min($user->minimumPasswordLength())]]);

        $resetPassword->handle($user, $this->newPassword);
        $this->newPassword = '';
        $this->dispatch('toast', message: __('users.password_changed', ['name' => $user->name]));
    }

    public function togglePlanned(): void
    {
        $this->authorize('update', User::class);
        $user = $this->user();

        if ($user->is_plannable) {
            $user->update(['is_plannable' => false]);

            return;
        }

        $attributes = ['is_plannable' => true, 'is_hidden_from_planning' => false];

        if ($user->agency_id === null || $user->agency?->planning_mode !== PlanningMode::Daily) {
            $attributes['all_agencies'] = true;
        }

        $user->update($attributes);
        $this->dispatch('toast', message: __('users.now_planned', ['name' => $user->name]));
    }

    public function toggleHiddenFromPlanning(): void
    {
        $this->authorize('update', User::class);
        $user = $this->user();

        $user->update(['is_hidden_from_planning' => ! $user->is_hidden_from_planning]);
    }

    /**
     * Design office availability: whether the design office user receives validation requests from the queue.
     */
    public function toggleReviewAvailability(ReviewQueue $queue): void
    {
        $this->authorize('update', User::class);
        $user = $this->user();
        abort_unless($user->hasRole(Role::DesignOffice->value), 404);
        $this->authorize('setAvailability', [AuditValidationRequest::class, $user]);

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $queue->setAvailability($actor, $user, ! $user->is_available_for_review);
    }

    /**
     * Downloads the person's personal data (RGPD-06) once the administrator's password is confirmed (AUT-07).
     */
    public function exportPersonalData(): void
    {
        $this->authorize('exportPersonalData', User::class);

        if ($this->passwordConfirmationNeeded(__FUNCTION__)) {
            return;
        }

        $this->redirectRoute('users.personal-data', ['user' => $this->userId]);
    }

    public function delete(DeleteUser $deleteUser): void
    {
        $person = $this->user();
        $this->authorize('delete', $person);

        $deleteUser->handle(auth()->user(), $person);

        $this->dispatch('user-deleted');
        $this->dispatch('toast', message: __('users.deleted'));
    }

    #[On('absences-changed')]
    public function refreshBadges(): void
    {
        // Re-render today's absence badge.
    }

    public function render(): View
    {
        $user = $this->user();
        $today = Carbon::today(config('platform.timezone'));

        return view('livewire.admin.users.user-card', [
            'user' => $user,
            'isSelf' => $user->is(auth()->user()),
            'isPlanned' => $user->isPlanned(),
            'isTechnician' => $user->hasRole(Role::Technician->value),
            'absenceToday' => $user->isPlanned() ? Absence::query()->where('user_id', $user->id)->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)->value('type') : null,
            'canManageAbsences' => Gate::allows('manage', Absence::class),
            'roles' => Role::cases(),
            'agencies' => Agency::query()->orderBy('sort_order')->get(),
            'companies' => Company::query()->orderBy('name')->get(),
            'platformName' => app(CompanySettings::class)->displayName(),
            'equipmentRoute' => ! $user->hasRole(Role::DesignOffice->value) && Gate::allows('manage', InventoryItem::class) ? route('equipment.users.show', $user) : null,
            'showsReviewAvailability' => $user->hasRole(Role::DesignOffice->value) && app(ModuleSettings::class)->isEnabled(Module::DesignOfficeWorkflow),
        ]);
    }

    /**
     * Changing a role needs the administrator's password (AUT-07). The new role's session durations
     * apply from the next sign-in: remembered devices are forgotten.
     */
    public function changeRole(string $role, ChangeUserRole $changeRole, UserSessions $sessions): void
    {
        $this->authorize('update', User::class);
        $newRole = Role::tryFrom($role);
        $by = auth()->user();
        $user = $this->user();

        if ($newRole === null || ! $by instanceof User || $user->hasRole($newRole->value)) {
            return;
        }

        if ($newRole !== Role::Administrator) {
            app(UserRules::class)->ensureAnotherAdministratorRemains($user);
        }

        if ($this->passwordConfirmationNeeded(__FUNCTION__, [$role])) {
            $this->role = (string) $user->getRoleNames()->first();

            return;
        }

        $changeRole->handle($by, $user, $newRole);
        $sessions->forgetRememberedDevices($user);
        $this->role = $newRole->value;
    }

    private function saveRole(): void
    {
        app()->call([$this, 'changeRole'], ['role' => $this->role]);
    }

    private function saveAgency(): void
    {
        $user = $this->user();

        if ($this->agency === 'all') {
            $user->update(['agency_id' => null, 'all_agencies' => true]);
        } else {
            $agencyId = $this->agency === '' ? null : (int) $this->agency;
            validator(['agency' => $agencyId], ['agency' => ['nullable', Rule::exists('agencies', 'id')]])->validate();

            $user->update(['agency_id' => $agencyId, 'all_agencies' => false]);
        }

        if ($user->wasChanged(['agency_id', 'all_agencies'])) {
            app(SecurityLog::class)->info(SecurityLog::AGENCY_CHANGED, ['user_id' => $user->id, 'agency_id' => $user->agency_id, 'all_agencies' => $user->all_agencies]);
        }
    }

    private function saveCompany(): void
    {
        $companyId = $this->companyId === '' ? null : (int) $this->companyId;
        validator(['companyId' => $companyId], ['companyId' => ['nullable', Rule::exists('companies', 'id')]])->validate();

        $this->user()->update(['company_id' => $companyId]);
    }

    private function saveContact(string $field): void
    {
        $user = $this->user();
        $value = trim($this->{$field}) ?: null;

        $rules = $field === 'email'
            ? ['nullable', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)]
            : ['nullable', 'string', 'max:40'];

        validator([$field => $value], [$field => $rules])->validate();

        $user->update([$field => $value]);
    }

    private function saveVehicle(): void
    {
        $user = $this->user();
        $model = trim($this->vehicleModel) ?: null;
        $plate = trim($this->vehiclePlate) ?: null;

        validator(['vehicleModel' => $model, 'vehiclePlate' => $plate], [
            'vehicleModel' => ['nullable', 'string', 'max:80'],
            'vehiclePlate' => ['nullable', 'string', 'max:20'],
        ])->validate();

        if ($user->vehicle === null && $model === null && $plate === null) {
            return;
        }

        $user->vehicle()->updateOrCreate([], ['model' => $model, 'plate' => $plate]);
    }

    private function askDepartureConfirmation(): void
    {
        $user = $this->user();

        if ($user->is(auth()->user())) {
            $this->departureDate = $user->departure_date?->toDateString() ?? '';

            throw ValidationException::withMessages(['departureDate' => __('users.errors.own_departure')]);
        }

        $date = $this->departureDate;
        $this->departureDate = $user->departure_date?->toDateString() ?? '';

        if ($date === '') {
            $message = __('users.confirm_cancel_departure', ['name' => $user->name]);
        } else {
            $remainingTasks = Task::query()->where('user_id', $user->id)->whereDate('date', '>=', $date)->count();
            $message = __('users.confirm_departure', ['name' => $user->name, 'date' => Carbon::parse($date)->translatedFormat('d M Y')])
                .($remainingTasks > 0 ? "\n\n".__('users.departure_tasks', ['count' => $remainingTasks]) : '');
        }

        $this->dispatch('confirm-action', message: $message, method: 'confirmDeparture', params: [$date], componentId: $this->getId());
    }

    private function fillFrom(User $user): void
    {
        $this->role = (string) $user->getRoleNames()->first();
        $this->agency = $user->all_agencies ? 'all' : (string) ($user->agency_id ?? '');
        $this->companyId = (string) ($user->company_id ?? '');
        $this->email = (string) $user->email;
        $this->phone = (string) $user->phone;
        $this->vehicleModel = (string) $user->vehicle?->model;
        $this->vehiclePlate = (string) $user->vehicle?->plate;
        $this->departureDate = $user->departure_date?->toDateString() ?? '';
    }

    private function user(): User
    {
        return User::query()->with(['roles', 'agency', 'vehicle'])->findOrFail($this->userId);
    }
}
