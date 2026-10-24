<?php

namespace App\Http\Requests\Api\V1\Me;

use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\CurrentMember;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Shared\Money\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PayMyMembershipRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * A membership can be paid in the app while it owes money and the gym
     * takes payments through Chapa. An open link is paid as it is, unless it
     * now asks for more than is due, because paying it would need a refund.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $problem = $this->problem();

                if ($problem !== null) {
                    [$field, $message] = $problem;
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }

    /**
     * The signed-in member's membership in the URL. Another member's isn't found.
     */
    public function membership(): Membership
    {
        return once(fn (): Membership => Membership::query()
            ->whereBelongsTo(app(CurrentMember::class)->get())
            ->findOrFail((int) $this->route('membership')));
    }

    /**
     * The membership's open payment link, if staff or the member created one.
     */
    public function openPaymentLink(): ?PaymentLink
    {
        return once(fn (): ?PaymentLink => PaymentLink::query()->whereBelongsTo($this->membership())->pending()->first());
    }

    /**
     * What the member pays: what the open link asks for, or else everything
     * still due on the membership.
     */
    public function amount(): Money
    {
        return once(function (): Money {
            $openPaymentLink = $this->openPaymentLink();

            return $openPaymentLink !== null ? $openPaymentLink->amount : Payment::balanceOf($this->membership())->balanceDue;
        });
    }

    public function chapaAccount(): ChapaAccount
    {
        return ChapaAccount::query()->firstOrFail();
    }

    /**
     * @return array{0: string, 1: string}|null the field and the message, or null when the membership can be paid
     */
    private function problem(): ?array
    {
        $openPaymentLink = $this->openPaymentLink();

        if ($openPaymentLink === null && ! ChapaAccount::query()->exists()) {
            return ['chapa_account', 'This gym doesn\'t take online payments yet.'];
        }

        $refusal = Payment::refusalToReceive($this->membership(), $this->amount());

        if ($refusal !== null && $openPaymentLink !== null && $this->membership()->cancelled_at === null) {
            return ['membership', 'This membership has an open payment link for more than is due now. Ask the gym to cancel it.'];
        }

        if ($refusal !== null) {
            return [$refusal->field, $refusal->getMessage()];
        }

        return $this->amount()->isPositive() ? null : ['membership', 'Nothing is due on this membership.'];
    }
}
