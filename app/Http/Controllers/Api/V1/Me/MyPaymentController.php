<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Billing\Models\Payment;
use App\Domains\Members\CurrentMember;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Me\MyPaymentResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MyPaymentController extends Controller
{
    /**
     * The signed-in member's receipts at the gym, payments and refunds, newest
     * first, 25 per page.
     */
    public function index(CurrentMember $currentMember): AnonymousResourceCollection
    {
        $payments = Payment::query()
            ->whereRelation('membership', 'member_id', $currentMember->get()->id)
            ->with(MyPaymentResource::RELATIONS)
            ->orderByDesc('paid_on')
            ->orderByDesc('receipt_number')
            ->paginate(25);

        return MyPaymentResource::collection($payments);
    }

    /**
     * One of the signed-in member's receipts at the gym.
     */
    public function show(CurrentMember $currentMember, int $payment): MyPaymentResource
    {
        $receipt = Payment::query()
            ->whereRelation('membership', 'member_id', $currentMember->get()->id)
            ->with(MyPaymentResource::RELATIONS)
            ->findOrFail($payment);

        return new MyPaymentResource($receipt);
    }
}
