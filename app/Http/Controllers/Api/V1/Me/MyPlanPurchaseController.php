<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Memberships\Pricing\PriceChoice;
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
     * payment. The link is for what the member pays: the plan's price, their
     * discount group's or the promo code's, whichever is lowest. Asking again
     * for the same plan at the same price gives the same open link; another
     * plan or price cancels the unpaid one. See ADR 0013, ADR 0030 and
     * ADR 0032.
     */
    public function store(BuyPlanRequest $request): JsonResponse
    {
        $openPurchase = $request->openPurchase();
        $priceChoice = $request->priceChoice();

        if ($openPurchase !== null && $this->isTheSamePurchase($openPurchase, $request, $priceChoice)) {
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
                priceChoice: $priceChoice,
            );
        } catch (UniqueConstraintViolationException) {
            // A second tap opened the link first; a member buys one plan at a time.
            return $this->answer(PaymentLink::query()->whereBelongsTo($request->member())->whereNotNull('plan_id')->pending()->sole());
        }

        return $this->answer($paymentLink)->setStatusCode(Response::HTTP_CREATED);
    }

    private function isTheSamePurchase(PaymentLink $openPurchase, BuyPlanRequest $request, PriceChoice $priceChoice): bool
    {
        return $openPurchase->plan_id === $request->plan()->id
            && $openPurchase->promo_code_id === $priceChoice->promoCode?->id
            && $openPurchase->discount_group_id === $priceChoice->discountGroup?->id
            && $openPurchase->amount->equals($priceChoice->price);
    }

    private function answer(PaymentLink $paymentLink): JsonResponse
    {
        return (new MyPaymentLinkResource($paymentLink->load(['payment', 'plan', 'promoCode'])))->response();
    }
}
