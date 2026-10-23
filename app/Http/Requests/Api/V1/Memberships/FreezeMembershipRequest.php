<?php

namespace App\Http\Requests\Api\V1\Memberships;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class FreezeMembershipRequest extends FormRequest
{
    /** The longest a single freeze can last. */
    private const MAXIMUM_DAYS = 366;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('freeze', $this->membership());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'starts_on' => ['nullable', 'bail', 'date_format:Y-m-d', $this->notInThePastUnlessAllowed()],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * The freeze must fit the membership: start while it runs, not overlap
     * another freeze, and stay within the freeze days its plan allowed unless
     * an owner or manager says otherwise.
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

                $problem = $this->problemWithTheDates();

                if ($problem !== null) {
                    [$field, $message] = $problem;
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }

    /**
     * The first day of the freeze: the one sent, or else today.
     */
    public function startsOn(): CarbonImmutable
    {
        if ($this->filled('starts_on')) {
            return CarbonImmutable::parse($this->string('starts_on')->toString());
        }

        return $this->today();
    }

    public function endsOn(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('ends_on')->toString());
    }

    /**
     * @return array{0: string, 1: string}|null the field and the message, or null when the dates are fine
     */
    private function problemWithTheDates(): ?array
    {
        $membership = $this->membership();
        $startsOn = $this->startsOn();
        $endsOn = $this->endsOn();

        if ($membership->cancelled_at !== null) {
            return ['membership', 'This membership is cancelled.'];
        }

        if ($endsOn->isBefore($startsOn)) {
            return ['ends_on', 'The last day of the freeze can\'t be before its first day.'];
        }

        $days = (int) $startsOn->diffInDays($endsOn) + 1;

        if ($days > self::MAXIMUM_DAYS) {
            return ['ends_on', 'A freeze can last at most '.self::MAXIMUM_DAYS.' days.'];
        }

        if ($startsOn->isBefore($membership->starts_on) || $startsOn->isAfter($membership->ends_on)) {
            return ['starts_on', sprintf(
                'A freeze must start while the membership runs, from %s to %s.',
                $membership->starts_on->format('j F Y'),
                $membership->ends_on->format('j F Y'),
            )];
        }

        $otherFreeze = $membership->freezes()
            ->where('starts_on', '<=', $endsOn->toDateString())
            ->where('ends_on', '>=', $startsOn->toDateString())
            ->first();

        if ($otherFreeze !== null) {
            return ['starts_on', sprintf(
                'This membership is already frozen from %s to %s.',
                $otherFreeze->starts_on->format('j F Y'),
                $otherFreeze->ends_on->format('j F Y'),
            )];
        }

        $daysLeft = $membership->freeze_days_allowed - $membership->freezeDaysUsed();

        if ($days > $daysLeft && ! $this->user()->can('freezeBeyondAllowance', Membership::class)) {
            return ['ends_on', $daysLeft > 0
                ? "This membership can be frozen for {$daysLeft} more ".Str::plural('day', $daysLeft).'.'
                : 'This membership has no freeze days left.'];
        }

        return null;
    }

    private function notInThePastUnlessAllowed(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $startsInThePast = CarbonImmutable::parse((string) $value)->lessThan($this->today());

            if ($startsInThePast && ! $this->user()->can('backdate', Membership::class)) {
                $fail('Only owners and managers can start a freeze on a past date.');
            }
        };
    }

    private function membership(): Membership
    {
        return $this->route('membership');
    }

    private function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }
}
