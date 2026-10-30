<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** Plain cookie remembering the visitor's language, also read where no session runs (error pages of unknown URLs). */
    public const string COOKIE = 'locale';

    private const int COOKIE_MINUTES = 60 * 24 * 365;

    /**
     * Pick the interface language: the user's saved choice, then the session choice, then the remembered
     * language cookie, then the browser's preferred language, then the application default. Runs globally
     * (no session yet: cookie and browser only) and again in the web group, where it also refreshes the cookie.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $supported */
        $supported = config('app.supported_locales');

        $hasSession = $request->hasSession();
        $remembered = $request->cookie(self::COOKIE);

        $locale = collect([
            $hasSession ? $request->user()?->locale : null,
            $hasSession ? $request->session()->get('locale') : null,
            is_string($remembered) ? $remembered : null,
            $this->browserLocale($request, $supported),
        ])->first(fn (?string $candidate) => $candidate !== null && in_array($candidate, $supported, true)) ?? config('app.locale');

        App::setLocale($locale);

        if ($hasSession && $remembered !== $locale) {
            Cookie::queue(Cookie::make(self::COOKIE, $locale, self::COOKIE_MINUTES, secure: null, httpOnly: true, sameSite: 'lax'));
        }

        return $next($request);
    }

    /**
     * @param  list<string>  $supported
     */
    private function browserLocale(Request $request, array $supported): ?string
    {
        $byLanguage = collect($supported)->mapWithKeys(fn (string $locale) => [strtolower(substr($locale, 0, 2)) => $locale]);

        foreach ($request->getLanguages() as $language) {
            $language = str_replace('-', '_', $language);

            if (in_array($language, $supported, true)) {
                return $language;
            }

            if ($match = $byLanguage->get(strtolower(substr($language, 0, 2)))) {
                return $match;
            }
        }

        return null;
    }
}
