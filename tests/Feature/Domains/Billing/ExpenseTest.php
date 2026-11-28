<?php

declare(strict_types=1);

use App\Domains\Activity\Models\ActivityRecord;
use App\Domains\Billing\Enums\DefaultExpenseCategory;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Exceptions\ExpenseRefused;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\ExpenseCategory;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00', 'Africa/Addis_Ababa'));
    $this->gym = actingAsOrganization(Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']));
    ExpenseCategory::startListFor($this->gym);
    $this->main = Branch::factory()->for($this->gym)->create();
    $this->bole = Branch::factory()->for($this->gym)->create();
    $this->owner = Staff::factory()->for($this->gym)->owner()->create();
    $this->manager = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Manager]);
    $this->manager->assignToBranches($this->bole);
    $this->rent = ExpenseCategory::query()->where('default_key', DefaultExpenseCategory::Rent)->sole();
    $this->today = CarbonImmutable::parse('2026-11-28');
});

it('records an expense, logged, flagged when dated before today', function () {
    $today = Expense::record($this->owner, $this->rent, Money::of('15,000'), $this->today, PaymentMethod::BankTransfer, null, 'Ato Kebede', 'FT123');
    Expense::record($this->owner, $this->rent, Money::of('500'), $this->today->subDays(3), PaymentMethod::Cash, $this->main);

    $logged = ActivityRecord::query()->where('action', 'expense_recorded')->orderBy('id')->get();

    expect($today->branch_id)->toBeNull()
        ->and($today->payee)->toBe('Ato Kebede')
        ->and($logged)->toHaveCount(2)
        ->and($logged[0]->flagged)->toBeFalse()
        ->and($logged[1]->flagged)->toBeTrue();
});

it('refuses what can\'t be spent', function (string $why, Closure $record, string $message) {
    expect(fn () => $record($this))->toThrow(ExpenseRefused::class, $message);
})->with([
    'nothing' => ['amount', fn ($test) => Expense::record($test->owner, $test->rent, Money::zero(), $test->today, PaymentMethod::Cash, null), 'how much'],
    'tomorrow' => ['day', fn ($test) => Expense::record($test->owner, $test->rent, Money::of('10'), $test->today->addDay(), PaymentMethod::Cash, null), 'after today'],
    'online' => ['method', fn ($test) => Expense::record($test->owner, $test->rent, Money::of('10'), $test->today, PaymentMethod::Chapa, null), 'Choose cash'],
    'whole gym by a manager' => ['branch', fn ($test) => Expense::record($test->manager, $test->rent, Money::of('10'), $test->today, PaymentMethod::Cash, null), 'whole gym'],
    'another branch' => ['branch', fn ($test) => Expense::record($test->manager, $test->rent, Money::of('10'), $test->today, PaymentMethod::Cash, $test->main), 'branch you work at'],
]);

it('refuses an archived category', function () {
    $this->rent->delete();

    expect(fn () => Expense::record($this->owner, $this->rent, Money::of('10'), $this->today, PaymentMethod::Cash, null))->toThrow(ExpenseRefused::class, 'archived');
});

it('voids an expense with a reason, once, so it no longer counts', function () {
    $expense = Expense::record($this->owner, $this->rent, Money::of('15,000'), $this->today, PaymentMethod::Cash, null);
    Expense::record($this->owner, $this->rent, Money::of('2,000'), $this->today, PaymentMethod::Cash, $this->main);

    expect(fn () => $expense->void($this->owner, ' '))->toThrow(ExpenseRefused::class, 'Say why');

    $expense->void($this->owner, 'Recorded twice.');

    expect(Expense::totalsOf(Expense::query())['spent']->toDecimal())->toBe('2000.00')
        ->and(ActivityRecord::query()->where('action', 'expense_voided')->sole()->flagged)->toBeTrue()
        ->and(fn () => $expense->void($this->owner, 'again'))->toThrow(ExpenseRefused::class, 'already voided');
});

it('shows managers their branches\' expenses, and owners the whole gym\'s too', function () {
    Expense::record($this->owner, $this->rent, Money::of('15,000'), $this->today, PaymentMethod::Cash, null);
    Expense::record($this->owner, $this->rent, Money::of('2,000'), $this->today, PaymentMethod::Cash, $this->main);
    Expense::record($this->manager, $this->rent, Money::of('1,000'), $this->today, PaymentMethod::Cash, $this->bole);

    expect(Expense::totalsOf(Expense::query()->seenBy($this->owner))['spent']->toDecimal())->toBe('18000.00')
        ->and(Expense::totalsOf(Expense::query()->seenBy($this->manager))['spent']->toDecimal())->toBe('1000.00');
});
