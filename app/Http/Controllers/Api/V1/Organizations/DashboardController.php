<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Dashboards\StaffDashboard;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Dashboard\ShowDashboardRequest;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * The gym at a glance for the period (today by default): money taken and
     * owed, check-ins, members, and memberships about to run out. Money is
     * shown only to the roles that see it, and staff who work at some
     * branches see only those. See ADR 0014.
     */
    public function __invoke(ShowDashboardRequest $request, CurrentOrganization $currentOrganization, StaffDashboard $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->sections(
            $currentOrganization->get(),
            $request->from(),
            $request->to(),
            $request->branchIds(),
            $request->seesMoney(),
        )]);
    }
}
