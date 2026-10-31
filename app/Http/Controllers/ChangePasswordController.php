<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChangePasswordController extends Controller
{
    /**
     * Password change required before using the platform (legacy accounts and initial administrators).
     */
    public function __invoke(Request $request): View|RedirectResponse
    {
        if (! $request->user()?->must_change_password) {
            return redirect('/');
        }

        return view('auth.change-password', [
            'minimumLength' => User::MINIMUM_PASSWORD_LENGTH,
        ]);
    }
}
