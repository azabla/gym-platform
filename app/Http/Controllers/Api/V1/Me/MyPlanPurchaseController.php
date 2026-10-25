<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Billing\Models\PaymentLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\BuyPlanRequest;
use App\Http\Resources\V1\Me\MyPaymentLinkResource;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class MyPlanPurchaseController extends Controller
{
    /**
     * Start buying the plan: a Chapa payment link for its price, for the
     * member's home branch. The membership is created once Chapa confirms the
     * payment. Asking again for the same plan gives the same open link;
     * choosing another plan cancels the unpaid one. See ADR 0013.
     */
    public function store(BuyPlanRequest $request): JsonResponse
    {
        $openPurchase = $request->openPurchase();

        if ($openPurchase !== null && $openPurchase->plan_id === $request->plan()->id) {
            return $this->answer($openPurchase);
        }

        $openPurchase?->cancelForAnotherPlan();

        try {
            $paymentLink = PaymentLink::openForPlan(
                member: $request->member(),
                plan: $request->plan(),
                branch: $request->member()->homeBranch,
                chapaAccount: $request->chapaAccount(),
                callbackUrl: route('api.v1.chapa.callback'),
            );
        } catch (UniqueConstraintViolationException) {
            // A second tap opened the link first; a member buys one plan at a time.
            return $this->answer(PaymentLink::query()->whereBelongsTo($request->member())->whereNotNull('plan_id')->pending()->sole());
        }

        return $this->answer($paymentLink)->setStatusCode(Response::HTTP_CREATED);
    }

    private function answer(PaymentLink $paymentLink): JsonResponse
    {
        return (new MyPaymentLinkResource($paymentLink->load(['payment', 'plan'])))->response();
    }
}
