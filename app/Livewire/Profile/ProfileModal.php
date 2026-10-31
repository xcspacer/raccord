<?php

namespace App\Livewire\Profile;

use App\Actions\Files\UploadRules;
use App\Actions\Users\UpdateProfile;
use App\Ai\AnthropicClient;
use App\Enums\Module;
use App\Enums\UserDocumentType;
use App\Models\User;
use App\Settings\ModuleSettings;
use App\Support\DayPunchRules;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * « Mon profil » (✎): every signed-in person edits their own contact details, password and documents.
 */
class ProfileModal extends Component
{
    use WithFileUploads;

    public bool $open = false;

    public string $email = '';

    public string $phone = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public ?TemporaryUploadedFile $btpCard = null;

    public ?TemporaryUploadedFile $drivingLicence = null;

    #[On('open-profile')]
    public function openModal(): void
    {
        $user = $this->user();

        $this->resetValidation();
        $this->reset('currentPassword', 'newPassword', 'btpCard', 'drivingLicence');
        $this->email = (string) $user->email;
        $this->phone = (string) $user->phone;
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function save(UpdateProfile $updateProfile): void
    {
        $user = $this->user();
        $this->authorize('updateProfile', $user);
        $this->email = trim($this->email);
        $this->phone = trim($this->phone);
        $wantsNewPassword = $this->newPassword !== '';
        $documentRules = ['nullable', ...UploadRules::document(UploadRules::PERSONAL_DOCUMENT, 15360)];

        $this->validate([
            'email' => ['nullable', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'currentPassword' => $wantsNewPassword ? ['required', 'current_password:web'] : ['nullable'],
            'newPassword' => $wantsNewPassword ? ['string', Password::min(User::MINIMUM_PASSWORD_LENGTH), 'different:currentPassword'] : ['nullable'],
            'btpCard' => $documentRules,
            'drivingLicence' => $documentRules,
        ]);

        $updateProfile->handle(
            $user,
            trim($this->email) ?: null,
            trim($this->phone) ?: null,
            $wantsNewPassword ? $this->newPassword : null,
            [UserDocumentType::BtpCard->value => $this->btpCard, UserDocumentType::DrivingLicence->value => $this->drivingLicence],
            session()->getId(),
        );

        $this->open = false;
        $this->dispatch('toast', message: __('profile.saved'));
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.profile.profile-modal', [
            'documents' => $this->open ? $user->documents()->get()->keyBy(fn ($document) => $document->type->value) : collect(),
            'documentTypes' => UserDocumentType::cases(),
            'recordsLocation' => $this->open && app(DayPunchRules::class)->appliesTo($user),
            'usesAi' => $this->open && app(AnthropicClient::class)->isConfigured(),
            'sharedWithClients' => $this->open && app(ModuleSettings::class)->isEnabled(Module::ClientPortal),
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
