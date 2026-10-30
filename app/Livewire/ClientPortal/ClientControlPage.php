<?php

namespace App\Livewire\ClientPortal;

use App\Actions\Files\UploadRules;
use App\Actions\Planning\ResolveClient;
use App\ClientPortal\ClientAccountJournal;
use App\ClientPortal\ClientRemarks;
use App\Enums\ClientRemarkStatus;
use App\Livewire\Concerns\ConfirmsPassword;
use App\Models\Audit;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\ClientRemark;
use App\Models\User;
use App\Settings\CompanySettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * « Contrôle client »: the client companies' portal links and branding, the portal accounts (create, switch off,
 * new password, audits they see, delete) and the notes clients leave, answered, closed or reopened here.
 *
 * Passwords are made by the server and shown once, in a dialog, to the person who created or reset them; the
 * client must choose their own at the next sign-in. Creating an account and setting a new password first ask for the
 * password of the person doing it.
 */
class ClientControlPage extends Component
{
    use ConfirmsPassword;
    use WithFileUploads;

    public const int PASSWORD_MIN = 10;

    public string $name = '';

    public string $company = '';

    public string $username = '';

    public bool $allAudits = false;

    /** @var list<int|string> */
    public array $auditIds = [];

    #[Locked]
    public ?int $passwordFor = null;

    public string $newPassword = '';

    /**
     * Password just made, shown in this response only: never kept in the component state sent to the browser.
     *
     * @var array{name: string, username: string, password: string}|null
     */
    protected ?array $revealed = null;

    #[Locked]
    public ?int $auditsFor = null;

    public bool $editAllAudits = true;

    /** @var list<int|string> */
    public array $editAuditIds = [];

    #[Locked]
    public ?int $brandingFor = null;

    public string $subtitle = '';

    public string $documentLocale = 'fr';

    public ?TemporaryUploadedFile $logo = null;

    public string $notesFilter = 'open';

    #[Locked]
    public ?int $replyTo = null;

    public string $replyText = '';

    public function mount(): void
    {
        $this->guard();
    }

    public function create(ResolveClient $clients, ClientAccountJournal $journal): void
    {
        $this->guard();

        $this->username = Str::lower(trim($this->username));

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'company' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9._@-]+$/', Rule::unique('client_accounts', 'username')],
            'auditIds' => [Rule::requiredIf(! $this->allAudits), 'array'],
            'auditIds.*' => ['integer', Rule::exists('audits', 'id')->whereNull('deleted_at')],
        ], ['username.unique' => __('client_portal.control.username_taken'), 'auditIds.required' => __('client_portal.control.audits_required')]);

        if ($this->passwordConfirmationNeeded(__FUNCTION__)) {
            return;
        }

        $password = $this->randomPassword();

        $account = ClientAccount::query()->make([
            'client_id' => $clients->handle($this->company)->id,
            'name' => trim($this->name),
            'username' => $this->username,
            'password' => $password,
            'has_all_audits' => $this->allAudits,
        ]);
        $account->must_change_password = true;
        $account->save();

        if (! $this->allAudits) {
            $account->audits()->sync($this->auditIds);
        }

        $journal->created($account, $this->actor());

        $this->reset('name', 'company', 'username', 'allAudits', 'auditIds');
        $this->revealed = ['name' => $account->name, 'username' => $account->username, 'password' => $password];
        $this->dispatch('toast', message: __('client_portal.control.created'));
    }

    public function toggle(int $id, ClientAccountJournal $journal): void
    {
        $this->guard();

        $account = ClientAccount::query()->findOrFail($id);
        $account->update(['is_active' => ! $account->is_active]);

        $journal->switched($account, $this->actor());
    }

    public function startPassword(int $id): void
    {
        $this->guard();

        $this->closeEditors();
        $this->passwordFor = ClientAccount::query()->findOrFail($id)->id;
    }

    public function savePassword(ClientAccountJournal $journal): void
    {
        $this->guard();

        $account = ClientAccount::query()->findOrFail($this->passwordFor);
        $password = $this->newPassword === '' ? $this->randomPassword() : $this->newPassword;

        validator(['newPassword' => $password], ['newPassword' => ['string', 'min:'.self::PASSWORD_MIN, 'max:255']])->validate();

        if ($this->passwordConfirmationNeeded(__FUNCTION__)) {
            return;
        }

        $account->forceFill(['password' => $password, 'must_change_password' => true])->save();
        $journal->passwordReset($account, $this->actor());

        $this->closeEditors();
        $this->revealed = ['name' => $account->name, 'username' => $account->username, 'password' => $password];
    }

    public function hidePassword(): void
    {
        $this->revealed = null;
    }

    public function startAudits(int $id): void
    {
        $this->guard();

        $account = ClientAccount::query()->with('audits:id')->findOrFail($id);

        $this->closeEditors();
        $this->auditsFor = $account->id;
        $this->editAllAudits = $account->has_all_audits;
        $this->editAuditIds = $account->audits->pluck('id')->all();
    }

    public function saveAudits(ClientAccountJournal $journal): void
    {
        $this->guard();

        $account = ClientAccount::query()->findOrFail($this->auditsFor);
        $hadAllAudits = $account->has_all_audits;

        $this->validate([
            'editAuditIds' => [Rule::requiredIf(! $this->editAllAudits), 'array'],
            'editAuditIds.*' => ['integer', Rule::exists('audits', 'id')->whereNull('deleted_at')],
        ], ['editAuditIds.required' => __('client_portal.control.audits_required')]);

        $account->update(['has_all_audits' => $this->editAllAudits]);
        $account->audits()->sync($this->editAllAudits ? [] : $this->editAuditIds);

        $journal->auditsChanged($account, $this->actor(), $hadAllAudits);

        $this->closeEditors();
    }

    public function delete(int $id, ClientAccountJournal $journal): void
    {
        $this->guard();

        $account = ClientAccount::query()->findOrFail($id);
        $journal->deleted($account, $this->actor());
        $account->delete();

        $this->closeEditors();
    }

    public function startBranding(int $clientId): void
    {
        $this->guard();

        $client = Client::query()->findOrFail($clientId);

        $this->closeEditors();
        $this->brandingFor = $client->id;
        $this->subtitle = (string) $client->portal_subtitle;
        $this->documentLocale = (string) ($client->locale ?? 'fr');
    }

    public function saveBranding(): void
    {
        $this->guard();

        $client = Client::query()->findOrFail($this->brandingFor);

        $this->validate([
            'subtitle' => ['nullable', 'string', 'max:160'],
            'documentLocale' => ['required', Rule::in(config('app.supported_locales'))],
            'logo' => ['nullable', ...UploadRules::document(UploadRules::LOGO, 2048)],
        ]);

        $attributes = ['portal_subtitle' => trim($this->subtitle) === '' ? null : trim($this->subtitle), 'locale' => $this->documentLocale];

        if ($this->logo !== null) {
            $attributes['logo_path'] = $this->logo->store('branding/clients', 'local');

            if ($client->logo_path !== null) {
                Storage::disk('local')->delete($client->logo_path);
            }
        }

        $client->update($attributes);

        $this->closeEditors();
        $this->dispatch('toast', message: __('client_portal.control.branding_saved'));
    }

    public function removeLogo(): void
    {
        $this->guard();

        $client = Client::query()->findOrFail($this->brandingFor);

        if ($client->logo_path !== null) {
            Storage::disk('local')->delete($client->logo_path);
            $client->update(['logo_path' => null]);
        }
    }

    public function filterNotes(string $filter): void
    {
        $this->notesFilter = $filter === 'all' ? 'all' : 'open';
    }

    public function startReply(int $remarkId): void
    {
        $this->authorize('manage', ClientRemark::class);

        $this->replyTo = $this->replyTo === $remarkId ? null : ClientRemark::query()->findOrFail($remarkId)->id;
        $this->reset('replyText');
    }

    public function sendReply(ClientRemarks $remarks): void
    {
        $this->resetValidation();
        $this->authorize('manage', ClientRemark::class);

        $remarks->staffReply($this->actor(), ClientRemark::query()->findOrFail($this->replyTo), $this->replyText, 'replyText');
        $this->reset('replyTo', 'replyText');
    }

    public function closeNote(int $remarkId, ClientRemarks $remarks): void
    {
        $this->authorize('manage', ClientRemark::class);

        $remarks->close(ClientRemark::query()->findOrFail($remarkId));
    }

    public function reopenNote(int $remarkId, ClientRemarks $remarks): void
    {
        $this->authorize('manage', ClientRemark::class);

        $remarks->reopen(ClientRemark::query()->findOrFail($remarkId));
    }

    public function cancel(): void
    {
        $this->closeEditors();
    }

    public function render(): View
    {
        $accounts = ClientAccount::query()->with(['client', 'audits:id,cabinet_key'])->orderBy('name')->get();

        return view('livewire.client-portal.client-control-page', [
            'accounts' => $accounts,
            'clients' => Client::query()->whereIn('id', $accounts->pluck('client_id'))->orderBy('name')->get(),
            'clientNames' => Client::query()->where('is_active', true)->orderBy('name')->pluck('name'),
            'audits' => $this->audits(),
            'revealed' => $this->revealed,
            'remarks' => ClientRemark::query()->with(['audit:id,cabinet_key', 'replies'])
                ->when($this->notesFilter === 'open', fn ($query) => $query->where('status', '!=', ClientRemarkStatus::Closed->value))
                ->latest()->latest('id')->get(),
            'company' => app(CompanySettings::class)->displayName(),
            'openNotes' => ClientRemark::query()->where('status', '!=', ClientRemarkStatus::Closed->value)->count(),
        ])->layout('components.layouts.app', ['title' => __('client_portal.control.title'), 'page' => 'client-control']);
    }

    /**
     * @return Collection<int, Audit>
     */
    private function audits(): Collection
    {
        return Audit::query()->get(['id', 'cabinet_key'])->sortBy('cabinet_key', SORT_NATURAL)->values();
    }

    private function closeEditors(): void
    {
        $this->reset('passwordFor', 'newPassword', 'auditsFor', 'editAllAudits', 'editAuditIds', 'brandingFor', 'subtitle', 'logo');
        $this->resetValidation();
    }

    private function randomPassword(): string
    {
        return Str::password(12, symbols: false);
    }

    private function guard(): void
    {
        $this->authorize('manage', ClientAccount::class);
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
