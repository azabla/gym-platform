<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payments\CancelPaymentLinkRequest;
use App\Http\Requests\Api\V1\Payments\ListPaymentLinksRequest;
use App\Http\Requests\Api\V1\Payments\StorePaymentLinkRequest;
use App\Http\Resources\V1\PaymentLinkResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PaymentLinkController extends Controller
{
    /**
     * The gym's payment links, newest first, 25 per page. The optional filters
     * are ?status= and ?member_id=.
     */
    public function index(ListPaymentLinksRequest $request): AnonymousResourceCollection
    {
        $paymentLinks = PaymentLink::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('member_id'), fn ($query) => $query->whereRelation('membership', 'member_id', $request->integer('member_id')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        PaymentLinkResource::loadDetails(...$paymentLinks->items());

        return PaymentLinkResource::collection($paymentLinks);
    }

    /**
     * Create a link to Chapa's payment page for what the member owes on the
     * membership, or the amount sent. Staff share its checkout URL with the
     * member.
     */
    public function store(StorePaymentLinkRequest $request, Membership $membership, CurrentStaff $currentStaff): JsonResponse
    {
        $paymentLink = PaymentLink::open(
            membership: $membership,
            amount: $request->amount(),
            branch: $request->branch(),
            createdBy: $currentStaff->get(),
            chapaAccount: $request->chapaAccount(),
            callbackUrl: url('api/v1/chapa/callback'),
        );
        PaymentLinkResource::loadDetails($paymentLink);

        return (new PaymentLinkResource($paymentLink))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * One payment link of the gym.
     */
    public function show(PaymentLink $paymentLink): PaymentLinkResource
    {
        Gate::authorize('view', $paymentLink);

        PaymentLinkResource::loadDetails($paymentLink);

        return new PaymentLinkResource($paymentLink);
    }

    /**
     * Cancel the open link, for example because the member paid another way.
     */
    public function cancel(CancelPaymentLinkRequest $request, PaymentLink $paymentLink, CurrentStaff $currentStaff): PaymentLinkResource
    {
        $paymentLink->cancel($currentStaff->get());
        PaymentLinkResource::loadDetails($paymentLink);

        return new PaymentLinkResource($paymentLink);
    }
}
