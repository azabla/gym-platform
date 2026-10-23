<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Billing\Models\Payment;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payments\ListPaymentsRequest;
use App\Http\Requests\Api\V1\Payments\StorePaymentRequest;
use App\Http\Resources\V1\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    /**
     * The gym's payments and refunds, newest first, 25 per page. The optional
     * filters are the first and last day paid (?from= and ?to=), ?branch_id=,
     * ?method= and ?member_id=. The totals in meta cover every page; voided
     * payments are listed but don't count.
     */
    public function index(ListPaymentsRequest $request): AnonymousResourceCollection
    {
        $payments = Payment::query()
            ->when($request->filled('from'), fn ($query) => $query->where('paid_on', '>=', $request->string('from')->toString()))
            ->when($request->filled('to'), fn ($query) => $query->where('paid_on', '<=', $request->string('to')->toString()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('method'), fn ($query) => $query->where('method', $request->string('method')->toString()))
            ->when($request->filled('member_id'), fn ($query) => $query->whereRelation('membership', 'member_id', $request->integer('member_id')));

        $page = (clone $payments)
            ->orderByDesc('paid_on')
            ->orderByDesc('receipt_number')
            ->paginate(25)
            ->withQueryString();

        PaymentResource::loadDetails(...$page->items());

        return PaymentResource::collection($page)->additional([
            'meta' => ['totals' => Payment::totalsOf($payments)],
        ]);
    }

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
        PaymentResource::loadDetails($payment);

        return (new PaymentResource($payment))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * One payment or refund of the gym, as a receipt.
     */
    public function show(Payment $payment): PaymentResource
    {
        Gate::authorize('view', $payment);

        PaymentResource::loadDetails($payment);

        return new PaymentResource($payment);
    }
}
