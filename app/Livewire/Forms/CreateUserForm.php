<?php

namespace App\Livewire\Forms;

use App\Enums\Role;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Form;

class CreateUserForm extends Form
{
    public string $name = '';

    public string $username = '';

    public string $role = 'technician';

    public string $agencyId = '';

    public string $vehicle = '';

    public string $email = '';

    public string $phone = '';

    public string $password = '';

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $role = Role::tryFrom($this->role);

        return [
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:60', 'alpha_dash:ascii', Rule::unique('users', 'username')],
            'role' => ['required', Rule::enum(Role::class)],
            'agencyId' => [$role !== Role::Administrator ? 'required' : 'nullable', 'nullable', Rule::exists('agencies', 'id')->whereNull('deleted_at')],
            'vehicle' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', Password::min($role === Role::Administrator ? 12 : 10)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'agencyId.required' => __('users.errors.agency_required'),
            'username.unique' => __('users.errors.username_taken'),
        ];
    }
}
