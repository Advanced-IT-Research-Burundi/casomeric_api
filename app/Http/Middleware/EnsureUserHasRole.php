<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve une route à certains rôles. Usage : ->middleware('role:admin,gestionnaire').
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user()?->hasRoles($roles)) {
            return sendError("Vous n'avez pas accès à cette ressource.", [], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
