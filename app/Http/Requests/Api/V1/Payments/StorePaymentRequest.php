<?php

namespace App\Http\Requests\Api\V1\Payments;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Billing\Models\Payment;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use App\Rules\ValidMoneyAmount;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Payment::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'bail', new ValidMoneyAmount, $this->moreThanZero()],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => [
                Rule::requiredIf(fn (): bool => $this->paymentMethodSent()?->hasReference() ?? false),
                Rule::prohibitedIf(fn (): bool => $this->paymentMethodSent() === PaymentMethod::Cash),
                'nullable',
                'bail',
                'string',
                'max:100',
                $this->notRecordedAlready(),
            ],
            'branch_id' => ['required', 'bail', 'integer', $this->branchTheStaffMemberWorksAt()],
            'paid_on' => ['nullable', 'bail', 'date_format:Y-m-d', $this->notInTheFuture(), $this->notInThePastUnlessAllowed()],
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
            'reference.required' => 'Enter the transaction number from the payment confirmation.',
            'reference.prohibited' => 'A cash payment has no transaction number.',
            'branch_id.required' => 'Choose the branch where the money was taken.',
        ];
    }

    /**
     * The membership must still be owed at least this much.
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

                if ($this->membership()->cancelled_at !== null) {
                    $validator->errors()->add('membership', 'This membership is cancelled, so nothing is due on it.');

                    return;
                }

                $balanceDue = Payment::balanceOf($this->membership())->balanceDue;

                if ($this->amount()->isGreaterThan($balanceDue)) {
                    $validator->errors()->add('amount', PaymentRefused::balanceDueMessage($balanceDue));
                }
            },
        ];
    }

    public function amount(): Money
    {
        return Money::of($this->string('amount')->toString(), $this->membership()->price->currency);
    }

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from($this->string('method')->toString());
    }

    /**
     * The transaction number, as it is stored, or null for cash.
     */
    public function reference(): ?string
    {
        return $this->filled('reference') ? $this->string('reference')->toString() : null;
    }

    public function branch(): Branch
    {
        return Branch::query()->findOrFail($this->integer('branch_id'));
    }

    /**
     * The day the money was paid: the one sent, or else today where the gym is.
     */
    public function paidOn(): CarbonImmutable
    {
        if ($this->filled('paid_on')) {
            return CarbonImmutable::parse($this->string('paid_on')->toString());
        }

        return $this->today();
    }

    /**
     * Transaction numbers are compared without spaces and in capitals, however
     * they were typed. Staff who work at only one branch take money there
     * unless they say otherwise.
     */
    protected function prepareForValidation(): void
    {
        $reference = $this->input('reference');

        if (is_string($reference)) {
            $tidiedReference = Payment::tidyReference($reference);
            $this->merge(['reference' => $tidiedReference === '' ? null : $tidiedReference]);
        }

        $branchesWorkedAt = $this->staff()->branchesWorkedAt();

        if (! $this->filled('branch_id') && $branchesWorkedAt->count() === 1) {
            $this->merge(['branch_id' => $branchesWorkedAt->sole()->id]);
        }
    }

    protected function membership(): Membership
    {
        return $this->route('membership');
    }

    protected function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }

    private function staff(): Staff
    {
        return app(CurrentStaff::class)->get();
    }

    /**
     * The method that was sent, if it is one we know.
     */
    private function paymentMethodSent(): ?PaymentMethod
    {
        $method = $this->input('method');

        return is_string($method) ? PaymentMethod::tryFrom($method) : null;
    }

    private function moreThanZero(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (Money::of((string) $value)->isZero()) {
                $fail('The amount must be more than zero.');
            }
        };
    }

    /**
     * A transaction number can be recorded only once for each method, unless
     * the payment that has it was voided.
     */
    private function notRecordedAlready(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $method = $this->paymentMethodSent();

            if ($method === null) {
                return;
            }

            $paymentWithTheReference = Payment::query()->counted()->where('method', $method)->where('reference', $value)->first();

            if ($paymentWithTheReference !== null) {
                $fail("This transaction number is already recorded, on receipt {$paymentWithTheReference->receipt_number}.");
            }
        };
    }

    /**
     * The branch must be a branch of this gym that the staff member works at.
     */
    private function branchTheStaffMemberWorksAt(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $branch = is_numeric($value) ? Branch::query()->find((int) $value) : null;

            if ($branch === null || ! $this->staff()->worksAt($branch)) {
                $fail('Choose a branch of this gym that you work at.');
            }
        };
    }

    private function notInTheFuture(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (CarbonImmutable::parse((string) $value)->greaterThan($this->today())) {
                $fail('A payment can\'t be dated in the future.');
            }
        };
    }

    private function notInThePastUnlessAllowed(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $paidInThePast = CarbonImmutable::parse((string) $value)->lessThan($this->today());

            if ($paidInThePast && ! $this->user()->can('backdate', Payment::class)) {
                $fail('Only owners, managers and accountants can date a payment in the past.');
            }
        };
    }
}
