<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Discount\Exceptions\InvalidDiscount;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
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
 * a discount, and it must fit around the member's other memberships. See
 * ADR 0006, ADR 0007 and ADR 0018.
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
                    ->options(fn (): array => Plan::query()->orderBy('name')->pluck('name', 'id')->all())
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
            ])
            ->action(function (array $data, Member $record, Action $action): void {
                $plan = Plan::query()->findOrFail((int) $data['plan_id']);
                $startsOn = CarbonImmutable::parse((string) $data['starts_on']);
                $refusal = Membership::refusalToSell($record, $plan, $startsOn, self::today());

                if ($refusal !== null) {
                    Refusals::halt($action, $refusal);
                }

                $discount = filled($data['discount'] ?? null)
                    ? Discount::parse((string) $data['discount'], (string) $data['discount_reason'], $plan->price->currency)
                    : null;

                $membership = Membership::sell($record, $plan, $startsOn, app(CurrentStaff::class)->get(), $discount);

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
