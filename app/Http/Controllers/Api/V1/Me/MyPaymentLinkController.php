<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\CurrentMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\PayMyMembershipRequest;
use App\Http\Resources\V1\Me\MyPaymentLinkResource;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class MyPaymentLinkController extends Controller
{
    /**
     * Pay what is owed on one of the signed-in member's memberships: its open
     * payment link, which staff may have created already, or else a new one
     * for everything still due, for the member's home branch. The app opens
     * the link's checkout URL. See ADR 0010.
     */
    public function store(PayMyMembershipRequest $request, CurrentMember $currentMember): JsonResponse
    {
        $openPaymentLink = $request->openPaymentLink();

        if ($openPaymentLink !== null) {
            return (new MyPaymentLinkResource($openPaymentLink->load('payment')))->response();
        }

        try {
            $paymentLink = PaymentLink::open(
                membership: $request->membership(),
                amount: $request->amount(),
                branch: $currentMember->get()->homeBranch,
                createdBy: null,
                chapaAccount: $request->chapaAccount(),
                callbackUrl: route('api.v1.chapa.callback'),
            );
        } catch (UniqueConstraintViolationException) {
            // A second tap opened the link first; a membership has one open link at a time.
            $paymentLink = PaymentLink::query()->whereBelongsTo($request->membership())->pending()->sole();

            return (new MyPaymentLinkResource($paymentLink->load('payment')))->response();
        }

        return (new MyPaymentLinkResource($paymentLink->load('payment')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * One of the signed-in member's payment links, with its receipt once paid.
     */
    public function show(CurrentMember $currentMember, int $paymentLink): MyPaymentLinkResource
    {
        return new MyPaymentLinkResource($this->myPaymentLink($currentMember, $paymentLink));
    }

    /**
     * Ask Chapa now whether the member has paid the link, as the app does when
     * the member comes back from Chapa's page, and record the payment if so.
     */
    public function check(CurrentMember $currentMember, int $paymentLink): MyPaymentLinkResource
    {
        $myPaymentLink = $this->myPaymentLink($currentMember, $paymentLink);
        $chapaAccount = ChapaAccount::query()->first();

        if ($chapaAccount !== null) {
            $myPaymentLink->check($chapaAccount);
        }

        return new MyPaymentLinkResource($myPaymentLink);
    }

    private function myPaymentLink(CurrentMember $currentMember, int $id): PaymentLink
    {
        return PaymentLink::query()
            ->whereBelongsTo($currentMember->get())
            ->with(['payment', 'plan'])
            ->findOrFail($id);
    }
}
