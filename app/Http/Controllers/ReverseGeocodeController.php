<?php

namespace App\Http\Controllers;

use App\Planning\ReverseGeocoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Place name for the stamp the camera prints on photos. Signed-in people only.
 */
class ReverseGeocodeController extends Controller
{
    public function __invoke(Request $request, ReverseGeocoder $geocoder): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);

        $place = $geocoder->lookup((float) $data['lat'], (float) $data['lng'], app()->getLocale());

        return response()->json(['ok' => $place !== null, 'place' => $place])->header('Cache-Control', 'private, no-store');
    }
}
