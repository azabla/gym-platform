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
     * payment. With a promo code, the link is for the plan's price with the
     * code. Asking again for the same plan and code gives the same open
     * link; choosing another plan or code cancels the unpaid one. See
     * ADR 0013 and ADR 0030.
     */
    public function store(BuyPlanRequest $request): JsonResponse
    {
        $openPurchase = $request->openPurchase();
        $promoCode = $request->promoCode();

        if ($openPurchase !== null && $openPurchase->plan_id === $request->plan()->id && $openPurchase->promo_code_id === $promoCode?->id) {
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
                priceChoice: $promoCode === null ? null : PriceChoice::withCode($request->plan()->price, $promoCode->priceFor($request->plan()), $promoCode),
            );
        } catch (UniqueConstraintViolationException) {
            // A second tap opened the link first; a member buys one plan at a time.
            return $this->answer(PaymentLink::query()->whereBelongsTo($request->member())->whereNotNull('plan_id')->pending()->sole());
        }

        return $this->answer($paymentLink)->setStatusCode(Response::HTTP_CREATED);
    }

    private function answer(PaymentLink $paymentLink): JsonResponse
    {
        return (new MyPaymentLinkResource($paymentLink->load(['payment', 'plan', 'promoCode'])))->response();
    }
}
