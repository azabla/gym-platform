<?php

declare(strict_types=1);

use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->boleBranch = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $this->member = Member::factory()->for($this->mainBranch, 'homeBranch')->create([
        'full_name' => 'Hana Girma',
        'phone' => '0933 45 67 89',
    ]);
    $this->monthly = Plan::factory()->for($this->gym)->create([
        'name' => 'Monthly',
        'price' => Money::of('2,500'),
        'duration_count' => 1,
        'duration_unit' => DurationUnit::Month,
    ]);
    $this->membership = Membership::factory()->for($this->member)->for($this->monthly)->create();
});

/**
 * POST a payment of the given details towards the membership.
 *
 * @param  array<string, mixed>  $details
 */
function recordPayment(Membership $membership, array $details): TestResponse
{
    return test()->postJson(
        route('api.v1.organizations.memberships.payments.store', ['organization' => 'demo-gym', 'membership' => $membership]),
        $details,
    );
}

it('lets a receptionist take part of the price in cash, then the rest by Telebirr', function () {
    $receptionist = actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    recordPayment($this->membership, ['amount' => '1,000', 'method' => 'cash'])
        ->assertCreated()
        ->assertJsonPath('data.receipt_number', 1)
        ->assertJsonPath('data.type', 'payment')
        ->assertJsonPath('data.amount', ['amount_minor' => 100000, 'currency' => 'ETB', 'formatted' => '1,000.00 ETB'])
        ->assertJsonPath('data.method', 'cash')
        ->assertJsonPath('data.reference', null)
        ->assertJsonPath('data.paid_on', '2026-10-23')
        ->assertJsonPath('data.branch.name', 'Main Branch')
        ->assertJsonPath('data.member', ['id' => $this->member->id, 'full_name' => 'Hana Girma', 'phone' => '+251933456789'])
        ->assertJsonPath('data.membership.plan.name', 'Monthly')
        ->assertJsonPath('data.membership.amount_paid.amount_minor', 100000)
        ->assertJsonPath('data.membership.balance_due.amount_minor', 150000)
        ->assertJsonPath('data.received_by.id', $receptionist->id);

    recordPayment($this->membership, ['amount' => '1,500', 'method' => 'telebirr', 'reference' => 'cj12 abc34d'])
        ->assertCreated()
        ->assertJsonPath('data.receipt_number', 2)
        ->assertJsonPath('data.reference', 'CJ12ABC34D')
        ->assertJsonPath('data.membership.balance_due.amount_minor', 0);
});

it('refuses more than the balance due, and anything once nothing is due', function () {
    Payment::factory()->for($this->membership)->create(['amount' => Money::of('2,000')]);
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    recordPayment($this->membership, ['amount' => '600', 'method' => 'cash'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount' => 'Only 500.00 ETB is still due on this membership.']);

    recordPayment($this->membership, ['amount' => '500', 'method' => 'cash'])->assertCreated();

    recordPayment($this->membership, ['amount' => '1', 'method' => 'cash'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount' => 'Nothing is due on this membership.']);
});

it('refuses a payment for a cancelled membership', function () {
    $cancelled = Membership::factory()->for($this->member)->cancelled()->create(['starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    recordPayment($cancelled, ['amount' => '100', 'method' => 'cash'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['membership' => 'This membership is cancelled, so nothing is due on it.']);
});

it('needs a transaction number for every method but cash', function (array $details, string $message) {
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    recordPayment($this->membership, ['amount' => '100', ...$details])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reference' => $message]);
})->with([
    'Telebirr without one' => [['method' => 'telebirr'], 'Enter the transaction number from the payment confirmation.'],
    'CBE Birr without one' => [['method' => 'cbe_birr', 'reference' => '  '], 'Enter the transaction number from the payment confirmation.'],
    'a bank transfer without one' => [['method' => 'bank_transfer'], 'Enter the transaction number from the payment confirmation.'],
    'cash with one' => [['method' => 'cash', 'reference' => 'CJ12ABC34D'], 'A cash payment has no transaction number.'],
]);

it('leaves Chapa payments to Chapa, for payments and refunds alike', function () {
    $cancelled = Membership::factory()->for($this->member)->for($this->monthly)->cancelled()->create(['starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
    Payment::factory()->for($cancelled)->create();
    actingAsStaff($this->gym, StaffRole::Manager, $this->mainBranch);

    recordPayment($this->membership, ['amount' => '100', 'method' => 'chapa', 'reference' => 'APfqvRZJ0bEn'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['method' => 'Chapa can\'t be chosen by hand. Chapa payments are recorded when Chapa confirms them.']);

    $this->postJson(route('api.v1.organizations.memberships.refunds.store', ['organization' => 'demo-gym', 'membership' => $cancelled]), [
        'amount' => '100',
        'method' => 'chapa',
        'reference' => 'APfqvRZJ0bEn',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['method' => 'Chapa can\'t be chosen by hand. Chapa payments are recorded when Chapa confirms them.']);

    expect(Payment::query()->acrossOrganizations()->count())->toBe(1);
});

it('refuses a transaction number already recorded in the gym, however it is typed', function () {
    $earlier = Payment::factory()->for($this->membership)->byTelebirr('CJ12ABC34D')->create(['amount' => Money::of('1,000')]);
    Payment::factory()->byTelebirr('DK56XYZ78A')->create();
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    recordPayment($this->membership, ['amount' => '500', 'method' => 'telebirr', 'reference' => ' cj12abc34d'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reference' => "This transaction number is already recorded, on receipt {$earlier->receipt_number}."]);

    recordPayment($this->membership, ['amount' => '500', 'method' => 'telebirr', 'reference' => 'DK56XYZ78A'])
        ->assertCreated();
});

it('refuses an amount that is not a positive amount written as text', function (mixed $amount) {
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    recordPayment($this->membership, ['amount' => $amount, 'method' => 'cash'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount']);
})->with([
    'a number' => [1000],
    'a negative amount' => ['-100'],
    'zero' => ['0.00'],
    'three decimals' => ['10.505'],
    'words' => ['a thousand'],
]);

it('takes the money at the staff member\'s only branch, or asks which branch when they work at several', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    recordPayment($this->membership, ['amount' => '100', 'method' => 'cash'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_id' => 'Choose the branch where the money was taken.']);

    recordPayment($this->membership, ['amount' => '100', 'method' => 'cash', 'branch_id' => $this->boleBranch->id])
        ->assertCreated()
        ->assertJsonPath('data.branch.name', 'Bole Branch');
});

it('refuses a branch the staff member does not work at, or a branch of another gym', function () {
    $otherGymsBranch = Branch::factory()->create();
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    recordPayment($this->membership, ['amount' => '100', 'method' => 'cash', 'branch_id' => $this->boleBranch->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_id' => 'Choose a branch of this gym that you work at.']);

    recordPayment($this->membership, ['amount' => '100', 'method' => 'cash', 'branch_id' => $otherGymsBranch->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_id' => 'Choose a branch of this gym that you work at.']);
});

it('lets only owners, managers and accountants date a payment in the past', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    recordPayment($this->membership, ['amount' => '100', 'method' => 'cash', 'paid_on' => '2026-10-22'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['paid_on' => 'Only owners, managers and accountants can date a payment in the past.']);

    actingAsStaff($this->gym, StaffRole::Accountant, $this->mainBranch);

    recordPayment($this->membership, ['amount' => '100', 'method' => 'cash', 'paid_on' => '2026-10-22'])
        ->assertCreated()
        ->assertJsonPath('data.paid_on', '2026-10-22');
});

it('refuses a date in the future', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    recordPayment($this->membership, ['amount' => '100', 'method' => 'cash', 'branch_id' => $this->mainBranch->id, 'paid_on' => '2026-10-24'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['paid_on' => 'A payment can\'t be dated in the future.']);
});

it('does not let trainers take payments or see them', function () {
    $payment = Payment::factory()->for($this->membership)->create();
    actingAsStaff($this->gym, StaffRole::Trainer);

    recordPayment($this->membership, ['amount' => '100', 'method' => 'cash'])->assertForbidden();

    $this->getJson(route('api.v1.organizations.payments.show', ['organization' => 'demo-gym', 'payment' => $payment]))
        ->assertForbidden();

    $this->getJson(route('api.v1.organizations.payments.index', ['organization' => 'demo-gym']))
        ->assertForbidden();
});

it('lists the gym\'s payments and refunds newest first, with totals that leave out voided payments', function () {
    $abebesMembership = Membership::factory()
        ->for(Member::factory()->for($this->mainBranch, 'homeBranch')->state(['full_name' => 'Abebe Kebede']))
        ->for($this->monthly)
        ->create();
    Payment::factory()->for($this->membership)->create(['amount' => Money::of('1,000'), 'paid_on' => '2026-10-21']);
    Payment::factory()->for($this->membership)->byTelebirr('CJ12ABC34D')->create(['amount' => Money::of('1,500'), 'paid_on' => '2026-10-22']);
    Payment::factory()->for($abebesMembership)->create(['amount' => Money::of('2,500'), 'paid_on' => '2026-10-23']);
    Payment::factory()->for($abebesMembership)->refund()->create(['amount' => Money::of('500'), 'paid_on' => '2026-10-23']);
    Payment::factory()->for($abebesMembership)->voided()->create(['amount' => Money::of('100'), 'paid_on' => '2026-10-23']);
    Payment::factory()->create();
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->getJson(route('api.v1.organizations.payments.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.*.amount.amount_minor', [10000, 50000, 250000, 150000, 100000])
        ->assertJsonPath('data.*.member.full_name', ['Abebe Kebede', 'Abebe Kebede', 'Abebe Kebede', 'Hana Girma', 'Hana Girma'])
        ->assertJsonPath('data.0.void.reason', 'Recorded twice.')
        ->assertJsonPath('data.1.type', 'refund')
        ->assertJsonPath('data.1.void', null)
        ->assertJsonPath('meta.totals.received.amount_minor', 500000)
        ->assertJsonPath('meta.totals.refunded.amount_minor', 50000)
        ->assertJsonPath('meta.totals.net.amount_minor', 450000)
        ->assertJsonPath('meta.totals.by_method.cash.amount_minor', 300000)
        ->assertJsonPath('meta.totals.by_method.telebirr.amount_minor', 150000)
        ->assertJsonPath('meta.totals.by_method.cbe_birr.amount_minor', 0)
        ->assertJsonPath('meta.totals.by_method.bank_transfer.amount_minor', 0);
});

it('adds up every page of payments, not just the one shown', function () {
    Payment::factory()->count(26)->for($this->membership)->create(['amount' => Money::of('10'), 'branch_id' => $this->mainBranch->id]);
    actingAsStaff($this->gym, StaffRole::Accountant);

    $this->getJson(route('api.v1.organizations.payments.index', ['organization' => 'demo-gym']))
        ->assertJsonCount(25, 'data')
        ->assertJsonPath('meta.total', 26)
        ->assertJsonPath('meta.totals.received.amount_minor', 26000);
});

it('filters payments by the day paid, branch, method and member', function () {
    $abebe = Member::factory()->for($this->mainBranch, 'homeBranch')->create();
    $abebesMembership = Membership::factory()->for($abebe)->for($this->monthly)->create();
    Payment::factory()->for($this->membership)->create(['amount' => Money::of('1,000'), 'paid_on' => '2026-10-21', 'branch_id' => $this->mainBranch->id]);
    Payment::factory()->for($this->membership)->byTelebirr('CJ12ABC34D')->create(['amount' => Money::of('1,500'), 'paid_on' => '2026-10-22', 'branch_id' => $this->boleBranch->id]);
    Payment::factory()->for($abebesMembership)->create(['amount' => Money::of('2,500'), 'paid_on' => '2026-10-23', 'branch_id' => $this->mainBranch->id]);
    actingAsStaff($this->gym, StaffRole::Accountant);

    $amountsListedFor = fn (array $filters) => $this->getJson(route('api.v1.organizations.payments.index', ['organization' => 'demo-gym', ...$filters]))
        ->assertOk()
        ->json('data.*.amount.amount_minor');

    expect($amountsListedFor(['from' => '2026-10-22']))->toBe([250000, 150000])
        ->and($amountsListedFor(['to' => '2026-10-21']))->toBe([100000])
        ->and($amountsListedFor(['from' => '2026-10-22', 'to' => '2026-10-22']))->toBe([150000])
        ->and($amountsListedFor(['branch_id' => $this->boleBranch->id]))->toBe([150000])
        ->and($amountsListedFor(['method' => 'cash']))->toBe([250000, 100000])
        ->and($amountsListedFor(['member_id' => $abebe->id]))->toBe([250000]);
});

it('reports a bad filter', function (array $filters, string $field) {
    actingAsStaff($this->gym, StaffRole::Accountant);

    $this->getJson(route('api.v1.organizations.payments.index', ['organization' => 'demo-gym', ...$filters]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'a day in another format' => [['from' => '23/10/2026'], 'from'],
    'a last day before the first' => [['from' => '2026-10-23', 'to' => '2026-10-22'], 'to'],
    'an unknown method' => [['method' => 'paypal'], 'method'],
    'a branch that is not a number' => [['branch_id' => 'main'], 'branch_id'],
]);

it('cannot take a payment for a membership of another gym', function () {
    $otherGymsMembership = Membership::factory()->create();
    actingAsStaff($this->gym, StaffRole::Owner);

    recordPayment($otherGymsMembership, ['amount' => '100', 'method' => 'cash'])->assertNotFound();
});

it('shows a receipt, still naming an archived member, but not a receipt of another gym', function () {
    $payment = Payment::factory()->for($this->membership)->byTelebirr('CJ12ABC34D')->create([
        'amount' => Money::of('1,000'),
        'branch_id' => $this->mainBranch->id,
        'paid_on' => '2026-10-20',
    ]);
    $otherGymsPayment = Payment::factory()->create();
    $this->member->delete();
    actingAsStaff($this->gym, StaffRole::Accountant);

    $this->getJson(route('api.v1.organizations.payments.show', ['organization' => 'demo-gym', 'payment' => $payment]))
        ->assertOk()
        ->assertJsonPath('data.receipt_number', $payment->receipt_number)
        ->assertJsonPath('data.type', 'payment')
        ->assertJsonPath('data.amount.formatted', '1,000.00 ETB')
        ->assertJsonPath('data.method', 'telebirr')
        ->assertJsonPath('data.reference', 'CJ12ABC34D')
        ->assertJsonPath('data.paid_on', '2026-10-20')
        ->assertJsonPath('data.branch.name', 'Main Branch')
        ->assertJsonPath('data.member.full_name', 'Hana Girma')
        ->assertJsonPath('data.membership.price.amount_minor', 250000)
        ->assertJsonPath('data.membership.amount_paid.amount_minor', 100000)
        ->assertJsonPath('data.membership.balance_due.amount_minor', 150000)
        ->assertJsonPath('data.received_by.id', $payment->received_by_staff_id);

    $this->getJson(route('api.v1.organizations.payments.show', ['organization' => 'demo-gym', 'payment' => $otherGymsPayment]))
        ->assertNotFound();
});

it('shows on memberships and members what has been paid and what is still due', function () {
    Payment::factory()->for($this->membership)->create(['amount' => Money::of('1,000')]);
    Membership::factory()->for($this->member)->for($this->monthly)->create(['starts_on' => '2026-11-23', 'ends_on' => '2026-12-22']);
    Member::factory()->for($this->mainBranch, 'homeBranch')->create(['full_name' => 'Abebe Kebede']);
    actingAsStaff($this->gym, StaffRole::Trainer);

    $this->getJson(route('api.v1.organizations.memberships.show', ['organization' => 'demo-gym', 'membership' => $this->membership]))
        ->assertJsonPath('data.amount_paid.amount_minor', 100000)
        ->assertJsonPath('data.balance_due.amount_minor', 150000);

    $this->getJson(route('api.v1.organizations.members.memberships.index', ['organization' => 'demo-gym', 'member' => $this->member]))
        ->assertJsonPath('data.*.balance_due.amount_minor', [250000, 150000]);

    $this->getJson(route('api.v1.organizations.members.show', ['organization' => 'demo-gym', 'member' => $this->member]))
        ->assertJsonPath('data.membership.balance_due.amount_minor', 150000)
        ->assertJsonPath('data.balance_due.amount_minor', 400000);

    $this->getJson(route('api.v1.organizations.members.index', ['organization' => 'demo-gym']))
        ->assertJsonPath('data.*.full_name', ['Abebe Kebede', 'Hana Girma'])
        ->assertJsonPath('data.*.balance_due.amount_minor', [0, 400000]);
});

it('owes nothing more once a membership is cancelled, but still shows what was paid', function () {
    Payment::factory()->for($this->membership)->create(['amount' => Money::of('1,000')]);
    actingAsStaff($this->gym, StaffRole::Manager);

    $this->postJson(route('api.v1.organizations.memberships.cancellation.store', ['organization' => 'demo-gym', 'membership' => $this->membership]), [
        'reason' => 'Moved to another city.',
    ])
        ->assertOk()
        ->assertJsonPath('data.amount_paid.amount_minor', 100000)
        ->assertJsonPath('data.balance_due.amount_minor', 0);
});

it('lets an accountant void a payment recorded by mistake, which frees its transaction number', function () {
    $payment = Payment::factory()->for($this->membership)->byTelebirr('CJ12ABC34D')->create(['amount' => Money::of('1,000')]);
    $accountant = actingAsStaff($this->gym, StaffRole::Accountant, $this->mainBranch);

    $this->postJson(route('api.v1.organizations.payments.void', ['organization' => 'demo-gym', 'payment' => $payment]), [
        'reason' => 'Recorded for the wrong member.',
    ])
        ->assertOk()
        ->assertJsonPath('data.void.voided_by.id', $accountant->id)
        ->assertJsonPath('data.void.reason', 'Recorded for the wrong member.')
        ->assertJsonPath('data.membership.balance_due.amount_minor', 250000);

    $this->postJson(route('api.v1.organizations.payments.void', ['organization' => 'demo-gym', 'payment' => $payment]), [
        'reason' => 'Recorded twice.',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment' => 'This payment is already voided.']);

    recordPayment($this->membership, ['amount' => '1,000', 'method' => 'telebirr', 'reference' => 'CJ12ABC34D'])
        ->assertCreated();
});

it('refuses to void a payment while a refund depends on it', function () {
    $cancelled = Membership::factory()->for($this->member)->cancelled()->create(['starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
    $payment = Payment::factory()->for($cancelled)->create(['amount' => Money::of('1,000')]);
    Payment::factory()->for($cancelled)->refund()->create(['amount' => Money::of('1,000')]);
    actingAsStaff($this->gym, StaffRole::Manager);

    $this->postJson(route('api.v1.organizations.payments.void', ['organization' => 'demo-gym', 'payment' => $payment]), [
        'reason' => 'Recorded for the wrong member.',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment' => 'Voiding this payment would leave more refunded than paid. Void the refund first.']);
});

it('lets a manager refund part of a cancelled membership, but not more than was paid', function () {
    $cancelled = Membership::factory()->for($this->member)->for($this->monthly)->cancelled()->create(['starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
    Payment::factory()->for($cancelled)->create(['amount' => Money::of('2,500')]);
    $manager = actingAsStaff($this->gym, StaffRole::Manager, $this->mainBranch);

    $this->postJson(route('api.v1.organizations.memberships.refunds.store', ['organization' => 'demo-gym', 'membership' => $cancelled]), [
        'amount' => '1,000',
        'method' => 'telebirr',
        'reference' => 'RF56XYZ78A',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'refund')
        ->assertJsonPath('data.amount.amount_minor', 100000)
        ->assertJsonPath('data.reference', 'RF56XYZ78A')
        ->assertJsonPath('data.branch.name', 'Main Branch')
        ->assertJsonPath('data.received_by.id', $manager->id)
        ->assertJsonPath('data.membership.amount_paid.amount_minor', 150000)
        ->assertJsonPath('data.membership.balance_due.amount_minor', 0);

    $this->postJson(route('api.v1.organizations.memberships.refunds.store', ['organization' => 'demo-gym', 'membership' => $cancelled]), [
        'amount' => '2,000',
        'method' => 'cash',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount' => 'Only 1,500.00 ETB has been paid towards this membership.']);
});

it('refuses a refund for a membership that is not cancelled', function () {
    Payment::factory()->for($this->membership)->create(['amount' => Money::of('1,000')]);
    actingAsStaff($this->gym, StaffRole::Accountant, $this->mainBranch);

    $this->postJson(route('api.v1.organizations.memberships.refunds.store', ['organization' => 'demo-gym', 'membership' => $this->membership]), [
        'amount' => '500',
        'method' => 'cash',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['membership' => 'Only a cancelled membership can be refunded. Cancel it first.']);
});

it('does not let receptionists void payments or give refunds', function () {
    $payment = Payment::factory()->for($this->membership)->create(['amount' => Money::of('1,000')]);
    $cancelled = Membership::factory()->for($this->member)->cancelled()->create(['starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    $this->postJson(route('api.v1.organizations.payments.void', ['organization' => 'demo-gym', 'payment' => $payment]), [
        'reason' => 'Recorded by mistake.',
    ])->assertForbidden();

    $this->postJson(route('api.v1.organizations.memberships.refunds.store', ['organization' => 'demo-gym', 'membership' => $cancelled]), [
        'amount' => '100',
        'method' => 'cash',
    ])->assertForbidden();
});

it('answers like a refused request when another desk took the last payment first', function () {
    Route::post('api/test/payment-refused', fn () => throw PaymentRefused::moreThanBalanceDue(Money::of('500')));

    $this->postJson('api/test/payment-refused')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount' => 'Only 500.00 ETB is still due on this membership.']);
});
