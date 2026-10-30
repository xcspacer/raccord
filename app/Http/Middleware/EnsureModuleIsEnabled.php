<?php

namespace App\Http\Middleware;

use App\Enums\Module;
use App\Settings\ModuleSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleIsEnabled
{
    public function __construct(private ModuleSettings $modules) {}

    /**
     * Usage: ->middleware('module:otdr_acceptance'). A disabled module answers 404, as if it did not exist.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->modules->isEnabled(Module::from($module)), 404);

        return $next($request);
    }
}
