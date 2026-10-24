<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Billing\Models\Payment;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payments\StoreRefundRequest;
use App\Http\Resources\V1\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class RefundController extends Controller
{
    /**
     * Give money back on the cancelled membership. Responds with the refund's
     * receipt.
     */
    public function store(StoreRefundRequest $request, Membership $membership, CurrentStaff $currentStaff): JsonResponse
    {
        $refund = Payment::refund(
            membership: $membership,
            amount: $request->amount(),
            method: $request->paymentMethod(),
            reference: $request->reference(),
            branch: $request->branch(),
            paidOn: $request->paidOn(),
            refundedBy: $currentStaff->get(),
        );
        PaymentResource::loadDetails($refund);

        return (new PaymentResource($refund))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
