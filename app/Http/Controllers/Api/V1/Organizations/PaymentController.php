<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Billing\Models\Payment;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payments\StorePaymentRequest;
use App\Http\Resources\V1\MembershipResource;
use App\Http\Resources\V1\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    /**
     * Record a payment towards the membership. Responds with its receipt.
     */
    public function store(StorePaymentRequest $request, Membership $membership, CurrentStaff $currentStaff): JsonResponse
    {
        $payment = Payment::receive(
            membership: $membership,
            amount: $request->amount(),
            method: $request->paymentMethod(),
            reference: $request->reference(),
            branch: $request->branch(),
            paidOn: $request->paidOn(),
            receivedBy: $currentStaff->get(),
        );

        return $this->receipt($payment)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * One payment or refund of the gym, as a receipt.
     */
    public function show(Payment $payment): PaymentResource
    {
        Gate::authorize('view', $payment);

        return $this->receipt($payment);
    }

    /**
     * The payment as a receipt, with its membership's balance as it is now.
     */
    private function receipt(Payment $payment): PaymentResource
    {
        $payment->load(PaymentResource::RELATIONS);
        MembershipResource::attachBalances($payment->membership);

        return new PaymentResource($payment);
    }
}
