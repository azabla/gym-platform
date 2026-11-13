<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\PaymentsReportRequest;
use App\Reports\Csv;
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
}
