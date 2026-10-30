<?php

namespace App\Http\Controllers\ClientPortal;

use App\ClientPortal\PortalSession;
use App\Http\Controllers\Controller;
use App\Livewire\ClientPortal\ClientControlPage;
use App\Models\Client;
use App\Models\ClientAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Password change a client account must make before using the portal: the password it was given was made by the
 * staff (new account or new password) and shown to them.
 */
class PortalPasswordController extends Controller
{
    public function edit(Client $portalClient, PortalSession $portal): View|RedirectResponse
    {
        if (! $this->account($portalClient, $portal)->must_change_password) {
            return redirect()->route('portal.home', $portalClient->portalSlug());
        }

        return view('portal.password', ['client' => $portalClient, 'minimumLength' => ClientControlPage::PASSWORD_MIN]);
    }

    public function update(Request $request, Client $portalClient, PortalSession $portal): RedirectResponse
    {
        $account = $this->account($portalClient, $portal);

        $input = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:'.ClientControlPage::PASSWORD_MIN, 'max:255', 'confirmed'],
        ]);

        if (! Hash::check($input['current_password'], $account->password)) {
            return back()->withErrors(['current_password' => __('client_portal.password_change.wrong_current')]);
        }

        if ($input['password'] === $input['current_password']) {
            return back()->withErrors(['password' => __('client_portal.password_change.same')]);
        }

        $account->forceFill(['password' => $input['password'], 'must_change_password' => false])->save();
        $portal->start($account);

        return redirect()->route('portal.home', $portalClient->portalSlug());
    }

    private function account(Client $portalClient, PortalSession $portal): ClientAccount
    {
        $account = $portal->account($portalClient->id);
        abort_if($account === null, 401);

        return $account;
    }
}
