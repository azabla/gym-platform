<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\CheckInsReportRequest;
use App\Http\Requests\Api\V1\Reports\MembershipsReportRequest;
use App\Http\Requests\Api\V1\Reports\MembersReportRequest;
use App\Http\Requests\Api\V1\Reports\PaymentsReportRequest;
use App\Reports\CheckInsReport;
use App\Reports\Csv;
use App\Reports\MembershipsReport;
use App\Reports\MembersReport;
use App\Reports\PaymentsReport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The gym's records as CSV files, for a period and the staff member's
 * branches. See ADR 0024.
 */
class ReportController extends Controller
{
    /**
     * Payments and refunds paid in the period, as a CSV file. Refunds are
     * negative; voided ones are listed and marked.
     */
    public function payments(PaymentsReportRequest $request): StreamedResponse
    {
        return Csv::download(new PaymentsReport($request->period(), $request->paymentMethod(), $request->memberId()));
    }

    /**
     * Memberships sold in the period, with their price, what was paid and
     * what is owed, as a CSV file.
     */
    public function memberships(MembershipsReportRequest $request): StreamedResponse
    {
        return Csv::download(new MembershipsReport($request->period()));
    }

    /**
     * Check-ins in the period, let in or turned away, as a CSV file.
     */
    public function checkIns(CheckInsReportRequest $request): StreamedResponse
    {
        return Csv::download(new CheckInsReport($request->period(), $request->result()));
    }

    /**
     * The gym's members as they are today, as a CSV file.
     */
    public function members(MembersReportRequest $request, CurrentOrganization $currentOrganization): StreamedResponse
    {
        return Csv::download(new MembersReport($currentOrganization->get(), $request->branchIds(), $request->search()));
    }
}
