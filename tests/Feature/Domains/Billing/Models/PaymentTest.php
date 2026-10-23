<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A membership sold at the given price to a new member of the gym.
 */
function membershipSoldFor(string $price, Organization $gym): Membership
{
    return Membership::factory()
        ->for(Member::factory()->for(Branch::factory()->for($gym), 'homeBranch'))
        ->for(Plan::factory()->for($gym)->state(['price' => Money::of($price)]))
        ->create();
}

/**
 * Receive the amount for the membership, at a branch of its gym, acting as that gym.
 */
function receiveForMembership(Membership $membership, string $amount, PaymentMethod $method = PaymentMethod::Cash, ?string $reference = null): Payment
{
    actingAsOrganization($membership->organization);

    return Payment::receive(
        membership: $membership,
        amount: Money::of($amount),
        method: $method,
        reference: $reference,
        branch: Branch::factory()->for($membership->organization)->create(),
        paidOn: CarbonImmutable::parse('2026-10-23'),
        receivedBy: Staff::factory()->for($membership->organization)->create(),
    );
}

it('records the payment with the gym\'s next receipt number', function () {
    $gym = Organization::factory()->create();
    $membership = membershipSoldFor('2,500', $gym);
    $otherGymsMembership = membershipSoldFor('2,500', Organization::factory()->create());

    $first = receiveForMembership($membership, '1,000');
    $otherGymsFirst = receiveForMembership($otherGymsMembership, '2,500');
    $second = receiveForMembership($membership, '1,500');

    expect($first->refresh()->receipt_number)->toBe(1)
        ->and($second->receipt_number)->toBe(2)
        ->and($otherGymsFirst->receipt_number)->toBe(1)
        ->and($first->organization_id)->toBe($gym->id)
        ->and($first->amount)->toEqual(Money::of('1,000'))
        ->and($first->method)->toBe(PaymentMethod::Cash)
        ->and($first->paid_on->toDateString())->toBe('2026-10-23');
});

it('gives the receipt number back when the payment fails', function () {
    $membership = membershipSoldFor('2,500', Organization::factory()->create());

    try {
        DB::transaction(function () use ($membership): void {
            receiveForMembership($membership, '1,000');

            throw new RuntimeException('The receipt printer jammed.');
        });
    } catch (RuntimeException) {
    }

    expect(receiveForMembership($membership, '1,000')->receipt_number)->toBe(1);
});

it('refuses more than the balance due, even when nothing checked it first', function () {
    $membership = membershipSoldFor('2,500', Organization::factory()->create());
    receiveForMembership($membership, '2,000');

    receiveForMembership($membership, '600');
})->throws(PaymentRefused::class, 'Only 500.00 ETB is still due on this membership.');

it('refuses any payment for a cancelled membership', function () {
    $gym = Organization::factory()->create();
    $membership = membershipSoldFor('2,500', $gym);
    $membership->cancel(Staff::factory()->for($gym)->create(), 'Sold by mistake.');

    receiveForMembership($membership, '1');
})->throws(PaymentRefused::class, 'Nothing is due on this membership.');

it('stores the transaction number without spaces and in capitals', function () {
    $membership = membershipSoldFor('2,500', Organization::factory()->create());

    $payment = receiveForMembership($membership, '2,500', PaymentMethod::Telebirr, ' cj12 abc34d ');

    expect($payment->refresh()->reference)->toBe('CJ12ABC34D');
});

it('works out what was paid and what is due, counting refunds and leaving out voided payments', function () {
    $gym = Organization::factory()->create();
    $membership = membershipSoldFor('2,500', $gym);
    $unpaidMembership = membershipSoldFor('1,000', $gym);
    Payment::factory()->for($membership)->create(['amount' => Money::of('2,000')]);
    Payment::factory()->for($membership)->voided()->create(['amount' => Money::of('500')]);
    Payment::factory()->for($membership)->refund()->create(['amount' => Money::of('300')]);
    actingAsOrganization($gym);

    $balances = Payment::balancesOf([$membership, $unpaidMembership]);

    expect($balances[$membership->id]->amountPaid)->toEqual(Money::of('1,700'))
        ->and($balances[$membership->id]->balanceDue)->toEqual(Money::of('800'))
        ->and($balances[$unpaidMembership->id]->amountPaid)->toEqual(Money::zero())
        ->and($balances[$unpaidMembership->id]->balanceDue)->toEqual(Money::of('1,000'));
});

it('owes nothing more on a cancelled membership, but keeps what was paid', function () {
    $gym = Organization::factory()->create();
    $membership = membershipSoldFor('2,500', $gym);
    Payment::factory()->for($membership)->create(['amount' => Money::of('1,000')]);
    $membership->cancel(Staff::factory()->for($gym)->create(), 'Moved to another city.');
    actingAsOrganization($gym);

    $balance = Payment::balanceOf($membership);

    expect($balance->amountPaid)->toEqual(Money::of('1,000'))
        ->and($balance->balanceDue)->toEqual(Money::zero());
});

it('adds up what each member owes across their memberships', function () {
    $gym = Organization::factory()->create();
    $member = Member::factory()->for(Branch::factory()->for($gym), 'homeBranch')->create();
    $plan = Plan::factory()->for($gym)->create(['price' => Money::of('2,500')]);
    $october = Membership::factory()->for($member)->for($plan)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    Membership::factory()->for($member)->for($plan)->create(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    Membership::factory()->for($member)->for($plan)->cancelled()->create(['starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
    Payment::factory()->for($october)->create(['amount' => Money::of('1,000')]);
    $memberWithoutMemberships = Member::factory()->for(Branch::factory()->for($gym), 'homeBranch')->create();
    actingAsOrganization($gym);

    $balancesDue = Payment::balancesDueOf([$member->id, $memberWithoutMemberships->id]);

    expect($balancesDue)->toEqual([$member->id => Money::of('4,000')]);
});

it('refuses a zero amount, a transaction number on cash, none on Telebirr, or half a void', function (array $attributes) {
    Payment::factory()->create($attributes);
})->with([
    'a zero amount' => [['amount' => Money::zero()]],
    'cash with a transaction number' => [['method' => PaymentMethod::Cash, 'reference' => 'CJ12ABC34D']],
    'Telebirr without a transaction number' => [['method' => PaymentMethod::Telebirr, 'reference' => null]],
    'a void without a reason' => [['voided_at' => '2026-10-23 10:00:00']],
])->throws(QueryException::class, 'violates check constraint');

it('refuses a receipt number the gym already gave', function () {
    $payment = Payment::factory()->create();

    Payment::factory()->for($payment->membership)->create(['receipt_number' => $payment->receipt_number]);
})->throws(QueryException::class, 'violates unique constraint');

it('refuses a transaction number already recorded in the gym for the same method', function () {
    $payment = Payment::factory()->byTelebirr('CJ12ABC34D')->create();

    Payment::factory()->for($payment->membership)->byTelebirr('CJ12ABC34D')->create();
})->throws(QueryException::class, 'violates unique constraint');

it('accepts a transaction number again once it is voided, for another method, or in another gym', function () {
    $voided = Payment::factory()->byTelebirr('CJ12ABC34D')->voided()->create();

    $recordedAgain = Payment::factory()->for($voided->membership)->byTelebirr('CJ12ABC34D')->create();
    $byCbeBirr = Payment::factory()->for($voided->membership)->create(['method' => PaymentMethod::CbeBirr, 'reference' => 'CJ12ABC34D']);
    $inAnotherGym = Payment::factory()->byTelebirr('CJ12ABC34D')->create();

    expect([$recordedAgain->exists, $byCbeBirr->exists, $inAnotherGym->exists])->toBe([true, true, true]);
});

it('refuses a membership of another gym', function () {
    $branch = Branch::factory()->create();

    Payment::factory()->create(['organization_id' => $branch->organization_id, 'branch_id' => $branch->id]);
})->throws(QueryException::class, 'violates foreign key constraint');

it('refuses a branch of another gym', function () {
    Payment::factory()->create(['branch_id' => Branch::factory()]);
})->throws(QueryException::class, 'violates foreign key constraint');

it('refuses a staff member of another gym', function () {
    Payment::factory()->create(['received_by_staff_id' => Staff::factory()]);
})->throws(QueryException::class, 'violates foreign key constraint');
