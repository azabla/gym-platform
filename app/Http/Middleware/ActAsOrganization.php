<?php

namespace App\Http\Middleware;

use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acts as the organization whose slug is in the URL ({organization}), for that
 * organization's signed-in staff only. See ADR 0004.
 *
 * It runs before route-model binding (see bootstrap/app.php), so models bound
 * from the URL are already limited to this organization. An unknown slug and an
 * organization the user doesn't work at both get the same 404, so slugs can't be
 * probed. The slug is then removed from the route parameters, so controllers
 * receive only their own.
 */
class ActAsOrganization
{
    public function __construct(
        private CurrentOrganization $currentOrganization,
        private CurrentStaff $currentStaff,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $staff = Staff::query()
            ->acrossOrganizations()
            ->whereBelongsTo($request->user())
            ->whereRelation('organization', 'slug', $request->route('organization'))
            ->with('organization')
            ->first();

        abort_if($staff === null, Response::HTTP_NOT_FOUND);

        $this->currentOrganization->set($staff->organization);
        $this->currentStaff->set($staff);
        $request->route()->forgetParameter('organization');

        return $next($request);
    }
}
