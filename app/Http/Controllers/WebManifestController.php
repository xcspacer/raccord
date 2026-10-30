<?php

namespace App\Http\Controllers;

use App\Settings\CompanySettings;
use Illuminate\Http\JsonResponse;

class WebManifestController extends Controller
{
    /**
     * PWA manifest: the installation's name, with the platform's generic icons (customers can't brand
     * the installed app icon).
     */
    public function __invoke(CompanySettings $company): JsonResponse
    {
        $name = $company->displayName();

        return response()->json([
            'name' => $name,
            'short_name' => $name,
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#0E2836',
            'theme_color' => '#0E2836',
            'icons' => [
                ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => asset('icons/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], headers: ['Content-Type' => 'application/manifest+json']);
    }
}
