<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Discount\Exceptions\InvalidDiscount;
use App\Domains\Memberships\Exceptions\PromoCodeRefused;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Memberships\Models\MemberDiscount;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Memberships\Pricing\Pricing;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Resources\Memberships\MembershipResource;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;

/**
 * Sell a plan to the member, with the API's rules: it starts on the first
 * free day unless another is chosen, only owners and managers backdate or give
 * a discount, anyone who sells may use a promo code, and it must fit around
 * the member's other memberships. See ADR 0006, ADR 0007, ADR 0018 and ADR 0030.
 */
class SellMembershipAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'sellMembership';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Sell membership')
            ->visible(fn (): bool => Gate::allows('create', Membership::class))
            ->schema(fn (Member $record): array => [
                Select::make('plan_id')
                    ->label('Plan')
                    ->options(fn (): array => Plan::query()->onSaleAt($record->home_branch_id)->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('The plans on sale at the member\'s home branch.')
                    ->required()
                    ->live(),
                DatePicker::make('starts_on')
                    ->required()
                    ->default(Membership::firstFreeDayFor($record, self::today())->toDateString())
                    ->rules(fn (): array => [self::notInThePastUnlessAllowed()]),
                TextInput::make('discount')
                    ->helperText('An amount, such as 500, or a percentage, such as 10%.')
                    ->visible(fn (): bool => Gate::allows('discount', Membership::class))
                    ->rules(fn (Get $get): array => [self::discountThatFitsThePrice($get('plan_id'), (string) $get('discount_reason'))]),
                Textarea::make('discount_reason')
                    ->visible(fn (): bool => Gate::allows('discount', Membership::class))
                    ->requiredWith('discount')
                    ->maxLength(500),
                TextInput::make('promo_code')
                    ->label('Promo code')
                    ->maxLength(30)
                    ->live(onBlur: true)
                    ->hint(function () use ($record): ?string {
                        $group = self::groupOf($record);

                        return $group === null ? null : "In the {$group->name} group";
                    })
                    ->helperText(fn (Get $get): string => self::priceWithCode($record, $get('plan_id'), (string) $get('promo_code')))
                    ->rules(fn (Get $get): array => [self::usableCode($record, $get('plan_id'), filled($get('discount')))]),
            ])
            ->action(function (array $data, Member $record, Action $action): void {
                $plan = Plan::query()->findOrFail((int) $data['plan_id']);
                $startsOn = CarbonImmutable::parse((string) $data['starts_on']);
                $promoCode = filled($data['promo_code'] ?? null) ? PromoCode::findByCode((string) $data['promo_code']) : null;
                $refusal = ($promoCode === null && filled($data['promo_code'] ?? null) ? PromoCodeRefused::unknown() : null)
                    ?? $promoCode?->refusalToUse($record, $plan, self::today())
                    ?? Membership::refusalToSell($record, $plan, $startsOn, self::today());

                if ($refusal !== null) {
                    Refusals::halt($action, $refusal);
                }

                $discount = $promoCode === null && filled($data['discount'] ?? null)
                    ? Discount::parse((string) $data['discount'], (string) $data['discount_reason'], $plan->price->currency)
                    : null;
                $staff = app(CurrentStaff::class)->get();

                // A typed discount is used as it is; otherwise the member pays the lowest of the
                // plan's price, their discount group's and the code's, as in the API (ADR 0032).
                $membership = $discount !== null
                    ? Membership::sell($record, $plan, $startsOn, $staff, $discount)
                    : Membership::sellAt(Pricing::bestFor($record, $plan, self::today(), $promoCode), $record, $plan, $startsOn, $staff);

                Notification::make()->success()->title("{$plan->name} sold, from {$startsOn->format('j F Y')}.")->send();

                $action->redirect(MembershipResource::getUrl('view', ['record' => $membership]));
            });
    }

    private static function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }

    private static function notInThePastUnlessAllowed(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (CarbonImmutable::parse((string) $value)->lessThan(self::today()) && Gate::denies('backdate', Membership::class)) {
                $fail('Only owners and managers can start a membership on a past date.');
            }
        };
    }

    private static function groupOf(Member $member): ?DiscountGroup
    {
        return MemberDiscount::groupFor($member, self::today());
    }

    /**
     * What the member will pay without a typed discount, and why: their
     * discount group's price or the code's, whichever is lower (ADR 0032), or
     * how to use a code.
     */
    private static function priceWithCode(Member $member, mixed $planId, string $typed): string
    {
        $plan = is_numeric($planId) ? Plan::query()->find((int) $planId) : null;
        $promoCode = trim($typed) === '' ? null : PromoCode::findByCode($typed);
        $hint = 'A code the gym shares, such as NEWYEAR. Any staff member who sells can use one.';

        if ($plan === null) {
            return $hint;
        }

        $usableCode = $promoCode !== null && $promoCode->refusalToUse($member, $plan, self::today()) === null ? $promoCode : null;
        $choice = Pricing::bestFor($member, $plan, self::today(), $usableCode);

        return match (true) {
            $choice->promoCode !== null => "With {$choice->promoCode->code}: {$choice->price->format()} instead of {$plan->price->format()}.",
            $choice->discountGroup !== null => "{$choice->discountGroup->name} group: {$choice->price->format()} instead of {$plan->price->format()}.",
            default => $hint,
        };
    }

    /**
     * A code the member can use for the plan today, never with a typed
     * discount as well (ADR 0030).
     */
    private static function usableCode(Member $member, mixed $planId, bool $withADiscount): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($member, $planId, $withADiscount): void {
            $plan = is_numeric($planId) ? Plan::query()->find((int) $planId) : null;

            if ($plan === null || ! is_string($value) || trim($value) === '') {
                return;
            }

            if ($withADiscount) {
                $fail('Use a promo code or a discount, not both.');

                return;
            }

            $promoCode = PromoCode::findByCode($value);
            $refusal = $promoCode === null ? PromoCodeRefused::unknown() : $promoCode->refusalToUse($member, $plan, self::today());

            if ($refusal !== null) {
                $fail($refusal->getMessage());
            }
        };
    }

    /**
     * The discount must read as an amount or a percentage, and take something,
     * but not more than everything, off the plan's price.
     */
    private static function discountThatFitsThePrice(mixed $planId, string $reason): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($planId, $reason): void {
            $plan = is_numeric($planId) ? Plan::query()->find((int) $planId) : null;

            if ($plan === null || ! is_string($value) || $value === '') {
                return;
            }

            try {
                Discount::parse($value, $reason, $plan->price->currency)->appliedTo($plan->price);
            } catch (InvalidDiscount $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
