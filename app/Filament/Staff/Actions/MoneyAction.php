<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Billing\Models\Payment;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Filament\Staff\Resources\Payments\PaymentResource;
use App\Rules\ValidMoneyAmount;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;

/**
 * Money taken for a membership, or given back on it, at the desk, with the
 * API's rules (StorePaymentRequest): an amount the membership's money allows,
 * a method other than Chapa, the transaction number for every method but cash
 * and never one already recorded, a branch the staff member works at, and
 * today unless an owner, manager or accountant backdates it. See ADR 0008 and
 * ADR 0018.
 */
abstract class MoneyAction extends Action
{
    /**
     * Why the membership's money doesn't allow the amount, or null when it does.
     */
    abstract protected function refusal(Membership $membership, Money $amount): ?PaymentRefused;

    /**
     * Take or give back the money.
     */
    abstract protected function recordMoney(Membership $membership, Money $amount, PaymentMethod $method, ?string $reference, Branch $branch, CarbonImmutable $paidOn, Staff $staff): Payment;

    /**
     * The amount to suggest.
     */
    abstract protected function suggestedAmount(Membership $membership): Money;

    abstract protected function doneMessage(Payment $payment): string;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema(fn (Membership $record): array => [
            TextInput::make('amount')
                ->required()
                ->default($this->suggestedAmount($record)->toDecimal())
                ->rules(fn (): array => [new ValidMoneyAmount, self::moreThanZero()]),
            Select::make('method')
                ->required()
                ->options(collect(PaymentMethod::cases())
                    ->reject(fn (PaymentMethod $method): bool => $method->isOnline())
                    ->mapWithKeys(fn (PaymentMethod $method): array => [$method->value => PaymentResource::methodLabel($method)])
                    ->all())
                ->live(),
            TextInput::make('reference')
                ->label('Transaction number')
                ->visible(fn (Get $get): bool => PaymentMethod::tryFrom((string) $get('method'))?->hasReference() ?? false)
                ->required()
                ->maxLength(100)
                ->rules(fn (Get $get): array => [self::notRecordedAlready(PaymentMethod::tryFrom((string) $get('method')))]),
            Select::make('branch_id')
                ->label('Branch')
                ->required()
                ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all())
                ->default(MemberResource::onlyBranchWorkedAt()),
            DatePicker::make('paid_on')
                ->label('Paid on')
                ->required()
                ->default(self::today()->toDateString())
                ->rules(fn (): array => [self::notInTheFuture(), self::notInThePastUnlessAllowed()]),
        ])
            ->action(function (array $data, Membership $record, Action $action): void {
                $amount = Money::of((string) $data['amount'], $record->price->currency);
                $refusal = $this->refusal($record, $amount);

                if ($refusal !== null) {
                    Refusals::halt($action, $refusal);
                }

                $reference = filled($data['reference'] ?? null) ? Payment::tidyReference((string) $data['reference']) : null;

                try {
                    $payment = $this->recordMoney(
                        $record,
                        $amount,
                        PaymentMethod::from((string) $data['method']),
                        $reference,
                        Branch::query()->findOrFail((int) $data['branch_id']),
                        CarbonImmutable::parse((string) $data['paid_on']),
                        app(CurrentStaff::class)->get(),
                    );
                } catch (PaymentRefused $refusal) {
                    Refusals::halt($action, $refusal);
                }

                Notification::make()->success()->title($this->doneMessage($payment))->send();
            });
    }

    private static function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }

    private static function moreThanZero(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && Money::of($value)->isZero()) {
                $fail('The amount must be more than zero.');
            }
        };
    }

    private static function notRecordedAlready(?PaymentMethod $method): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($method): void {
            $payment = $method !== null && is_string($value) ? Payment::recordedWithReference($method, $value) : null;

            if ($payment !== null) {
                $fail("This transaction number is already recorded, on receipt {$payment->receipt_number}.");
            }
        };
    }

    private static function notInTheFuture(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (CarbonImmutable::parse((string) $value)->greaterThan(self::today())) {
                $fail('A payment can\'t be dated in the future.');
            }
        };
    }

    private static function notInThePastUnlessAllowed(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (CarbonImmutable::parse((string) $value)->lessThan(self::today()) && Gate::denies('backdate', Payment::class)) {
                $fail('Only owners, managers and accountants can date a payment in the past.');
            }
        };
    }
}
