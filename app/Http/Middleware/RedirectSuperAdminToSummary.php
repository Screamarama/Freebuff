<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super admins work from the per-tenant summary (Tenants page) and manage
 * records by impersonating a tenant admin. Keep them out of the cross-tenant
 * record lists entirely — even via direct URL.
 */
class RedirectSuperAdminToSummary
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role === 'super_admin') {
            return redirect()->route('tenants.index')
                ->with('success', 'Super admins see a per-tenant summary here — log in as a tenant admin to manage their records.');
        }

        return $next($request);
    }
}
