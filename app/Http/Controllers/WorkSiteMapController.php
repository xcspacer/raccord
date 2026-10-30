<?php

namespace App\Http\Controllers;

use App\Models\WorkSite;
use App\Planning\WorkSiteMap;
use App\Support\Today;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * JSON feed of the Accueil map, for managers only.
 */
class WorkSiteMapController extends Controller
{
    public function __invoke(Request $request, WorkSiteMap $map): JsonResponse
    {
        Gate::authorize('viewAny', WorkSite::class);

        return response()->json($map->build(Today::date(), $request->query('mode') !== 'all'))
            ->header('Cache-Control', 'private, no-store');
    }
}
