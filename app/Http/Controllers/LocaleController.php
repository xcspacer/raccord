<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Http\Requests\UpdateLocaleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

class LocaleController extends Controller
{
    /**
     * Save the chosen interface language for the session, in the language cookie and, when signed in, on the user profile.
     */
    public function __invoke(UpdateLocaleRequest $request): RedirectResponse
    {
        $locale = $request->validated('locale');

        $request->session()->put('locale', $locale);
        $request->user()?->update(['locale' => $locale]);
        Cookie::queue(Cookie::make(SetLocale::COOKIE, $locale, 60 * 24 * 365, secure: null, httpOnly: true, sameSite: 'lax'));

        return back();
    }
}
