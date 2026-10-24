<?php

namespace App\Http\Requests\Api\V1\Payments;

use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Shared\Money\Money;
use App\Http\Requests\Api\V1\Payments\Concerns\TakesMoneyForAMembership;
use App\Rules\ValidMoneyAmount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePaymentLinkRequest extends FormRequest
{
    use TakesMoneyForAMembership;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', PaymentLink::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['nullable', 'bail', new ValidMoneyAmount, $this->moreThanZero()],
            'branch_id' => ['required', 'bail', 'integer', $this->branchTheStaffMemberWorksAt()],
        ];
    }

    /**
     * Get the custom messages for the validator's rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'Choose the branch this payment is for.',
        ];
    }

    /**
     * The gym must have connected Chapa, the membership must not have an open
     * link already, and it must still be owed the amount.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $problem = $this->problem();

                if ($problem !== null) {
                    [$field, $message] = $problem;
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }

    /**
     * The amount sent, or else everything still due on the membership.
     */
    public function amount(): Money
    {
        if ($this->filled('amount')) {
            return Money::of($this->string('amount')->toString(), $this->membership()->price->currency);
        }

        return Payment::balanceOf($this->membership())->balanceDue;
    }

    public function chapaAccount(): ChapaAccount
    {
        return ChapaAccount::query()->firstOrFail();
    }

    protected function prepareForValidation(): void
    {
        $this->useTheOnlyBranchByDefault();
    }

    /**
     * @return array{0: string, 1: string}|null the field and the message, or null when the link can be created
     */
    private function problem(): ?array
    {
        if (! ChapaAccount::query()->exists()) {
            return ['chapa_account', 'Connect the gym\'s Chapa account first.'];
        }

        if (PaymentLink::query()->whereBelongsTo($this->membership())->pending()->exists()) {
            return ['membership', 'This membership already has an open payment link. Cancel it to create a new one.'];
        }

        $refusal = Payment::refusalToReceive($this->membership(), $this->amount());

        if ($refusal !== null) {
            return [$refusal->field, $refusal->getMessage()];
        }

        return $this->amount()->isPositive() ? null : ['amount', 'Nothing is due on this membership.'];
    }
}
