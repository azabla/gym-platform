<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\InsightRequest;
use App\Insights\InsightFigures;
use App\Reports\Csv;
use App\Reports\InsightReport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InsightController extends Controller
{
    /**
     * An insight's figures for the last 3, 6 or 12 months (?months=, 12 by
     * default) at the staff member's branches (?branch_id=): revenue,
     * renewals, busy-hours or members, for the roles each is for. See
     * ADR 0040.
     */
    public function show(InsightRequest $request, CurrentOrganization $currentOrganization, InsightFigures $figures): JsonResponse
    {
        return response()->json(['data' => [
            'insight' => $request->insight()->value,
            'months' => $request->months(),
            ...$figures->of($request->insight(), $currentOrganization->get(), $request->months(), $request->branchIds()),
        ]]);
    }

    /**
     * The same insight as a CSV file.
     */
    public function download(InsightRequest $request, CurrentOrganization $currentOrganization): StreamedResponse
    {
        return Csv::download(new InsightReport($request->insight(), $currentOrganization->get(), $request->months(), $request->branchIds()));
    }
}
