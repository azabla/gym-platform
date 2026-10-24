<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Models\CheckIn;
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
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->boleBranch = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $monthly = Plan::factory()->for($this->gym)->create([
        'name' => 'Monthly',
        'price' => Money::of('2,500'),
        'duration_count' => 1,
        'duration_unit' => DurationUnit::Month,
    ]);

    // Hana paid part of a membership that started today, at Main Branch.
    $this->hana = Member::factory()->for($this->mainBranch, 'homeBranch')->create(['full_name' => 'Hana Girma']);
    $hanas = Membership::factory()->for($this->hana)->for($monthly)->create(['starts_on' => '2026-10-24', 'ends_on' => '2026-11-23']);
    Payment::factory()->for($hanas)->create(['amount' => Money::of('1,000'), 'branch_id' => $this->mainBranch->id]);
    Payment::factory()->for($hanas)->create(['amount' => Money::of('300'), 'branch_id' => $this->mainBranch->id, 'paid_on' => '2026-10-21']);
    Payment::factory()->for($hanas)->voided()->create(['amount' => Money::of('500'), 'branch_id' => $this->mainBranch->id]);

    // Abebe paid in full at Bole Branch, and his membership ends in 3 days.
    $this->abebe = Member::factory()->for($this->boleBranch, 'homeBranch')->create(['full_name' => 'Abebe Bekele']);
    $this->abebes = Membership::factory()->for($this->abebe)->for($monthly)->create(['starts_on' => '2026-09-28', 'ends_on' => '2026-10-27']);
    Payment::factory()->for($this->abebes)->create(['amount' => Money::of('2,500'), 'branch_id' => $this->boleBranch->id]);

    // Kidus's membership ends in 2 days, but the next one is already sold. Neither is paid.
    $kidus = Member::factory()->for($this->mainBranch, 'homeBranch')->create(['full_name' => 'Kidus Alemu']);
    Membership::factory()->for($kidus)->for($monthly)->create(['starts_on' => '2026-09-27', 'ends_on' => '2026-10-26']);
    Membership::factory()->for($kidus)->for($monthly)->create(['starts_on' => '2026-10-27', 'ends_on' => '2026-11-26']);

    CheckIn::factory()->for($this->hana)->for($this->mainBranch)->count(2)->create();
    CheckIn::factory()->for($this->abebe)->for($this->boleBranch)->denied(CheckInDenial::MembershipExpired)->create();
    CheckIn::factory()->for($this->hana)->for($this->mainBranch)->create(['checked_in_at' => now()->subDays(2)]);

    Payment::factory()->create();
    CheckIn::factory()->create();
});

function dashboard(string $query = ''): TestResponse
{
    return test()->getJson(route('api.v1.organizations.dashboard', ['organization' => 'demo-gym']).$query);
}

it('shows the owner the gym today: money taken and owed, check-ins, members and who to call', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    dashboard()
        ->assertOk()
        ->assertJsonPath('data.period', ['from' => '2026-10-24', 'to' => '2026-10-24'])
        ->assertJsonPath('data.money.received.formatted', '3,500.00 ETB')
        ->assertJsonPath('data.money.refunded.formatted', '0.00 ETB')
        ->assertJsonPath('data.money.by_method.cash.formatted', '3,500.00 ETB')
        ->assertJsonPath('data.money.open_payment_links.count', 0)
        ->assertJsonPath('data.owed.total.formatted', '6,200.00 ETB')
        ->assertJsonPath('data.owed.members', 2)
        ->assertJsonPath('data.check_ins', ['allowed' => 2, 'denied' => 1, 'denied_by_reason' => ['MEMBERSHIP_EXPIRED' => 1]])
        ->assertJsonPath('data.members.active', 3)
        ->assertJsonPath('data.members.new', 3)
        ->assertJsonPath('data.members.memberships_sold', ['at_the_desk' => 4, 'in_the_app' => 0])
        ->assertJsonCount(1, 'data.expiring')
        ->assertJsonPath('data.expiring.0.member.full_name', 'Abebe Bekele')
        ->assertJsonPath('data.expiring.0.ends_on', '2026-10-27')
        ->assertJsonPath('data.expiring.0.balance_due.formatted', '0.00 ETB');
});

it('adds up a chosen period', function () {
    actingAsStaff($this->gym, StaffRole::Manager);

    dashboard('?from=2026-10-20&to=2026-10-24')
        ->assertOk()
        ->assertJsonPath('data.period', ['from' => '2026-10-20', 'to' => '2026-10-24'])
        ->assertJsonPath('data.money.received.formatted', '3,800.00 ETB')
        ->assertJsonPath('data.check_ins.allowed', 3);
});

it('narrows to one branch when asked', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    dashboard('?branch_id='.$this->mainBranch->id)
        ->assertJsonPath('data.money.received.formatted', '1,000.00 ETB')
        ->assertJsonPath('data.check_ins.allowed', 2)
        ->assertJsonPath('data.check_ins.denied', 0)
        ->assertJsonPath('data.members.active', 2)
        ->assertJsonCount(0, 'data.expiring');
});

it('shows staff who work at one branch only that branch', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->boleBranch);

    dashboard()
        ->assertJsonPath('data.check_ins', ['allowed' => 0, 'denied' => 1, 'denied_by_reason' => ['MEMBERSHIP_EXPIRED' => 1]])
        ->assertJsonPath('data.members.active', 1)
        ->assertJsonPath('data.expiring.0.member.full_name', 'Abebe Bekele');
});

it('shows receptionists and trainers no money, and accountants the money', function (StaffRole $role, bool $seesMoney) {
    actingAsStaff($this->gym, $role);

    $response = dashboard()->assertOk();

    expect(array_key_exists('money', $response->json('data')))->toBe($seesMoney)
        ->and(array_key_exists('owed', $response->json('data')))->toBe($seesMoney)
        ->and(array_key_exists('balance_due', $response->json('data.expiring.0')))->toBe($seesMoney);
})->with([
    'receptionist' => [StaffRole::Receptionist, false],
    'trainer' => [StaffRole::Trainer, false],
    'accountant' => [StaffRole::Accountant, true],
]);

it('refuses a period that ends before it starts, is over a year, or a branch the staff member does not work at', function (string $query, string $field) {
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    dashboard(str_replace('OTHER', (string) $this->boleBranch->id, $query))->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'backwards' => ['?from=2026-10-24&to=2026-10-20', 'to'],
    'over a year' => ['?from=2025-01-01&to=2026-10-24', 'to'],
    'not a date' => ['?from=yesterday', 'from'],
    'another branch' => ['?branch_id=OTHER', 'branch_id'],
]);
