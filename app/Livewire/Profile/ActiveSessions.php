<?php

namespace App\Livewire\Profile;

use App\Models\User;
use App\Support\UserSessions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * « Sessions actives » (security requirement SES-04, not in the legacy platform): the devices where a
 * person is signed in, shown in their own profile and on their card in « Gérer les utilisateurs ».
 * The person or an administrator can sign a device out (for a lost phone) or every device at once.
 */
class ActiveSessions extends Component
{
    #[Locked]
    public int $userId;

    public bool $expanded = false;

    public function mount(User $user): void
    {
        $this->userId = $user->id;
        $this->user();
    }

    public function toggle(): void
    {
        $this->user();
        $this->expanded = ! $this->expanded;
    }

    public function revoke(string $key, UserSessions $sessions): void
    {
        $user = $this->user();

        if ($sessions->revoke($user, $key, $this->currentSessionId())) {
            $this->dispatch('toast', message: __('sessions.revoked'));
        }
    }

    public function revokeAll(UserSessions $sessions): void
    {
        $count = $sessions->revokeAll($this->user(), $this->currentSessionId());

        $this->dispatch('toast', message: trans_choice('sessions.revoked_all', $count, ['count' => $count]));
    }

    public function render(UserSessions $sessions): View
    {
        $user = $this->user();

        return view('livewire.profile.active-sessions', [
            'user' => $user,
            'isSelf' => $user->is(auth()->user()),
            'sessions' => $this->expanded ? $sessions->list($user, $this->currentSessionId()) : [],
        ]);
    }

    /**
     * Only the person and administrators see and end someone's sessions.
     */
    private function user(): User
    {
        $user = User::query()->findOrFail($this->userId);

        $this->authorize('manageSessions', $user);

        return $user;
    }

    /**
     * The device making this request, which is never signed out from here.
     */
    private function currentSessionId(): ?string
    {
        return request()->hasSession() ? request()->session()->getId() : null;
    }
}
