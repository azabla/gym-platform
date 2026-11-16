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
use App\Rules\ValidMoneyAmount;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
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

        $this->modalWidth(Width::Large)
            ->modalDescription(fn (Membership $record): string => self::moneySummaryOf($record))
            ->schema(fn (Membership $record): array => [
                TextInput::make('amount')
                    ->required()
                    ->prefix('ETB')
                    ->placeholder('2,500')
                    ->default($this->suggestedAmount($record)->toDecimal())
                    ->helperText('Write it as on a receipt, such as 2,500 or 1500.50.')
                    ->rules(fn (): array => [new ValidMoneyAmount, self::moreThanZero()]),
                Select::make('method')
                    ->required()
                    ->native(false)
                    ->options(collect(PaymentMethod::cases())
                        ->reject(fn (PaymentMethod $method): bool => $method->isOnline())
                        ->mapWithKeys(fn (PaymentMethod $method): array => [$method->value => $method->label()])
                        ->all())
                    ->live(),
                TextInput::make('reference')
                    ->label('Transaction number')
                    ->placeholder('From the SMS or the bank slip, such as CJ12ABC34D')
                    ->prefixIcon(Heroicon::OutlinedHashtag)
                    ->helperText('Each transaction number can be recorded once.')
                    ->visible(fn (Get $get): bool => PaymentMethod::tryFrom((string) $get('method'))?->hasReference() ?? false)
                    ->required()
                    ->maxLength(100)
                    ->rules(fn (Get $get): array => [self::notRecordedAlready(PaymentMethod::tryFrom((string) $get('method')))]),
                Grid::make(2)->schema([
                    Select::make('branch_id')
                        ->label('Branch')
                        ->required()
                        ->native(false)
                        ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all())
                        ->default(MemberResource::onlyBranchWorkedAt()),
                    DatePicker::make('paid_on')
                        ->label('Paid on')
                        ->required()
                        ->native(false)
                        ->displayFormat('j M Y')
                        ->maxDate(self::today()->toDateString())
                        ->default(self::today()->toDateString())
                        ->rules(fn (): array => [self::notInTheFuture(), self::notInThePastUnlessAllowed()]),
                ]),
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

    /**
     * "Monthly for Hana Girma: price 2,500.00 ETB, paid 1,000.00 ETB, still owed 1,500.00 ETB."
     */
    private static function moneySummaryOf(Membership $membership): string
    {
        $balance = Payment::balanceOf($membership);

        return "{$membership->plan?->name} for {$membership->member?->full_name}: price {$membership->price->format()}, paid {$balance->amountPaid->format()}, still owed {$balance->balanceDue->format()}.";
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
