<?php

declare(strict_types=1);

namespace App\Filament\Staff\Middleware;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acts as the gym chosen in the staff panel, as ActAsOrganization does for the
 * API: it sets the current organization and the signed-in user's staff record
 * there, so scoped queries and the policies work. Filament has already checked
 * that the user works at the gym. See ADR 0017.
 */
class ActAsTenant
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
        $organization = Filament::getTenant();

        abort_unless($organization instanceof Organization, Response::HTTP_NOT_FOUND);

        $staff = Staff::query()
            ->acrossOrganizations()
            ->where('user_id', $request->user()?->getAuthIdentifier())
            ->whereBelongsTo($organization)
            ->first();

        abort_if($staff === null, Response::HTTP_NOT_FOUND);

        $staff->setRelation('organization', $organization);
        $this->currentOrganization->set($organization);
        $this->currentStaff->set($staff);

        return $next($request);
    }
}
