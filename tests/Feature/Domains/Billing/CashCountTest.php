<?php

declare(strict_types=1);

use App\Domains\Activity\Models\ActivityRecord;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Exceptions\CashCountRefused;
use App\Domains\Billing\Models\CashCount;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-28 18:00', 'Africa/Addis_Ababa'));
    $this->gym = actingAsOrganization(Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']));
    $this->main = Branch::factory()->for($this->gym)->create();
    $this->bole = Branch::factory()->for($this->gym)->create();
    $this->hana = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Receptionist]);
    $this->owner = Staff::factory()->for($this->gym)->owner()->create();
    $this->plan = Plan::factory()->for($this->gym)->create(['price' => Money::of('3,000')]);
});

/**
 * Take an amount for a new member's membership, by the method, at the branch, as the staff member.
 */
function take(string $amount, Staff $by, Branch $at, PaymentMethod $method = PaymentMethod::Cash): Payment
{
    $membership = Membership::sell(Member::factory()->for($at, 'homeBranch')->create(), test()->plan, CarbonImmutable::parse('2026-11-28'), $by);

    return Payment::receive($membership, Money::of($amount), $method, $method === PaymentMethod::Cash ? null : 'TX'.random_int(1000, 9999), $at, CarbonImmutable::parse('2026-11-28'), $by);
}

it('expects the cash the staff member took at the branch, less cash refunds and voids, and nothing else', function () {
    take('1,000', $this->hana, $this->main);
    take('500', $this->hana, $this->main)->void($this->owner, 'Wrong member.');
    $refunded = take('3,000', $this->hana, $this->main);
    $refunded->membership->cancel($this->owner, 'Moved away.');
    Payment::refund($refunded->membership, Money::of('400'), PaymentMethod::Cash, null, $this->main, CarbonImmutable::parse('2026-11-28'), $this->hana);
    take('700', $this->hana, $this->main, PaymentMethod::Telebirr);
    take('900', $this->hana, $this->bole);
    take('800', $this->owner, $this->main);

    expect(CashCount::expectedFor($this->hana, $this->main)->toDecimal())->toBe('3600.00')
        ->and(CashCount::expectedFor($this->hana, $this->bole)->toDecimal())->toBe('900.00');
});

it('closes a count, keeping later cash for the next one', function () {
    take('1,000', $this->hana, $this->main);

    $count = CashCount::close($this->hana, $this->main, Money::of('1,000'), null);
    take('250', $this->hana, $this->main);

    expect($count->expected->toDecimal())->toBe('1000.00')
        ->and($count->difference()->isZero())->toBeTrue()
        ->and($count->payments()->count())->toBe(1)
        ->and(CashCount::expectedFor($this->hana, $this->main)->toDecimal())->toBe('250.00');
});

it('needs a note when the count is off, and flags it in the log', function () {
    take('1,000', $this->hana, $this->main);

    expect(fn () => CashCount::close($this->hana, $this->main, Money::of('950'), ' '))->toThrow(CashCountRefused::class, '-50.00 ETB off');

    $count = CashCount::close($this->hana, $this->main, Money::of('950'), 'Gave 50 change twice.');
    $logged = ActivityRecord::query()->about($count)->sole();

    expect($count->difference()->toDecimal())->toBe('-50.00')
        ->and($logged->flagged)->toBeTrue()
        ->and($logged->details['note'])->toBe('Gave 50 change twice.');
});

it('refuses to close a count with nothing to count, so the same cash is never counted twice', function () {
    take('1,000', $this->hana, $this->main);
    CashCount::close($this->hana, $this->main, Money::of('1,000'), null);

    expect(fn () => CashCount::close($this->hana, $this->main, Money::of('1,000'), null))->toThrow(CashCountRefused::class, 'no cash to count');
});

it('leaves a count as it was when one of its payments is voided later', function () {
    $payment = take('1,000', $this->hana, $this->main);
    $count = CashCount::close($this->hana, $this->main, Money::of('1,000'), null);

    $payment->void($this->owner, 'Wrong member.');

    expect($count->refresh()->expected->toDecimal())->toBe('1000.00')
        ->and(CashCount::expectedFor($this->hana, $this->main)->isZero())->toBeTrue();
});

it('is checked once, by someone other than the counter unless they own the gym', function () {
    take('1,000', $this->hana, $this->main);
    $count = CashCount::close($this->hana, $this->main, Money::of('1,000'), null);

    expect(fn () => $count->check($this->hana, null))->toThrow(CashCountRefused::class, 'Someone else');

    $count->check($this->owner, 'Banked on Monday.');

    expect($count->refresh()->checked_at)->not->toBeNull()
        ->and($count->checkedBy?->is($this->owner))->toBeTrue()
        ->and(fn () => $count->check($this->owner, null))->toThrow(CashCountRefused::class, 'already checked');

    take('300', $this->owner, $this->main);
    $own = CashCount::close($this->owner, $this->main, Money::of('300'), null);
    $own->check($this->owner, null);

    expect($own->refresh()->checked_at)->not->toBeNull();
});

it('says who has cash waiting to be counted, at which branch', function () {
    take('1,000', $this->hana, $this->main);
    take('900', $this->hana, $this->bole);
    take('800', $this->owner, $this->main);
    CashCount::close($this->owner, $this->main, Money::of('800'), null);

    $waiting = collect(CashCount::waitingToBeCounted(null))->map(fn (array $row): array => [$row['staff_id'], $row['branch_id'], $row['amount']->toDecimal()])->sortBy(1)->values()->all();

    expect($waiting)->toEqualCanonicalizing([
        [$this->hana->id, $this->main->id, '1000.00'],
        [$this->hana->id, $this->bole->id, '900.00'],
    ])
        ->and(CashCount::waitingToBeCounted([$this->bole->id]))->toHaveCount(1);
});
