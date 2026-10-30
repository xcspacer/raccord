<?php

namespace App\Http\Controllers;

use App\Actions\Users\ExportPersonalData;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Personal data of a person (RGPD-06), for administrators only (UserPolicy::exportPersonalData) and only once
 * their password was confirmed recently on « Gérer les utilisateurs » (AUT-07).
 */
class PersonalDataExportController extends Controller
{
    public function __invoke(Request $request, User $user, ExportPersonalData $export): Response
    {
        Gate::authorize('exportPersonalData', $user);

        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        abort_if(now()->getTimestamp() - $confirmedAt >= (int) config('auth.password_timeout', 10800), 403);

        return $export->download($user);
    }
}
