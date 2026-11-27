<?php

declare(strict_types=1);

namespace App\Filament\Staff\Middleware;

use App\Domains\Identity\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows the staff panel in the signed-in staff member's message language:
 * our own words from lang/am.json, Filament's from its Amharic files, and
 * month and weekday names from Carbon's. The API and CSV files stay in
 * English. See ADR 0041.
 */
class UseStaffLanguage
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $language = $user->messageLanguage()->value;

            app()->setLocale($language);
            Carbon::setLocale($language);
            CarbonImmutable::setLocale($language);
        }

        return $next($request);
    }
}
