<?php

namespace App\Http\Middleware;

use App\Filament\Staff\PanelUser;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows the API docs (/docs/api) only to staff signed in to the staff panel,
 * in every environment. Visitors who aren't signed in go to the panel's
 * sign-in, which brings them back here; anyone else gets a 403. See ADR 0021.
 */
class LetStaffReadApiDocs
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $panel = Filament::getPanel('staff');
        $user = auth($panel->getAuthGuard())->user();

        if ($user === null) {
            return redirect()->guest($panel->getLoginUrl());
        }

        abort_unless($user instanceof PanelUser && $user->canAccessPanel($panel), 403);

        return $next($request);
    }
}
