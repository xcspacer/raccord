<?php

namespace App\Http\Controllers\FibreCode;

use App\Http\Controllers\Controller;
use App\Settings\CompanySettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Standalone « Code Fibre » app: public (no sign-in), installable on a phone and usable without a network once opened.
 */
class FibreCodeAppController extends Controller
{
    public function show(): View
    {
        return view('fibre-code.standalone');
    }

    /**
     * Manifest of the installed app: the installation's name, with the platform's generic icons.
     */
    public function manifest(CompanySettings $company): JsonResponse
    {
        $scope = route('fibre-code.app', absolute: false).'/';

        return response()->json([
            'name' => __('fibre_code.app.name', ['platform' => $company->displayName()]),
            'short_name' => __('fibre_code.app.short_name'),
            'description' => __('fibre_code.app.description'),
            'lang' => str_replace('_', '-', app()->getLocale()),
            'start_url' => $scope,
            'scope' => $scope,
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#14181B',
            'theme_color' => '#1C2226',
            'icons' => [
                ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
            ],
        ], headers: ['Content-Type' => 'application/manifest+json'], options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
