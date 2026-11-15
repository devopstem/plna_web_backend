<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks web admin panel for API-only roles (Programmer, Merchandiser).
 */
class EnsureApiOnlyRole
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isSuperAdmin()) {
            return $next($request);
        }

        $isApiOnly = false;
        foreach (Permissions::apiOnlyRoles() as $role) {
            if ($user->hasRole($role, 'web')) {
                $isApiOnly = true;
                break;
            }
        }

        if (! $isApiOnly) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->routeIs('logout', 'merchandiser.mobile-only')) {
            return $next($request);
        }

        $label = $user->isIntegrator() ? 'Программист' : 'Мерчандайзер';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => "Роль «{$label}» работает только через API / мобильное приложение.",
            ], 403);
        }

        return redirect()->route('merchandiser.mobile-only');
    }
}
