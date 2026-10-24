<?php

declare(strict_types=1);

use App\Domains\Attendance\Credentials\Gq1;
use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Enums\CredentialRevocation;
use App\Domains\Attendance\Models\AccessCredential;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 07:00:00', 'Africa/Addis_Ababa'));

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
    $this->credential = AccessCredential::factory()->for($this->member)->create();
});

/**
 * The code the member's phone shows right now, or the given number of
 * 30-second steps ago.
 */
function codeOnThePhone(AccessCredential $credential, int $stepsAgo = 0): string
{
    $step = Gq1::stepAt(CarbonImmutable::now()->getTimestamp()) - $stepsAgo;

    return Gq1::payload($credential->rawSecret(), $credential->publicId(), $step);
}

function scan(string $payload, Branch $branch, ?string $idempotencyKey = null): TestResponse
{
    return test()->postJson(
        route('api.v1.organizations.check-ins.scan', ['organization' => 'demo-gym']),
        ['payload' => $payload, 'branch_id' => $branch->id],
        $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey],
    );
}

it('lets the member in with a fresh code, showing the membership and what is still owed', function () {
    $receptionist = actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);
    Payment::factory()->for($this->membership)->create(['amount' => Money::of('1,000')]);

    scan(codeOnThePhone($this->credential), $this->mainBranch)
        ->assertOk()
        ->assertJsonPath('data.decision', 'allow')
        ->assertJsonPath('data.reason_code', null)
        ->assertJsonPath('data.method', 'qr')
        ->assertJsonPath('data.branch.name', 'Main Branch')
        ->assertJsonPath('data.member.full_name', 'Hana Girma')
        ->assertJsonPath('data.membership.plan.name', 'Monthly')
        ->assertJsonPath('data.membership.ends_on', '2026-11-23')
        ->assertJsonPath('data.membership.balance_due.formatted', '1,500.00 ETB')
        ->assertJsonPath('data.checked_in_by.id', $receptionist->id);

    $checkIn = CheckIn::query()->sole();
    expect($checkIn->result)->toBe(CheckInResult::Allowed)
        ->and($checkIn->member_id)->toBe($this->member->id)
        ->and($checkIn->access_credential_id)->toBe($this->credential->id);
});

it('lets every staff role scan at the door', function (StaffRole $role) {
    actingAsStaff($this->gym, $role);

    scan(codeOnThePhone($this->credential), $this->mainBranch)->assertOk()->assertJsonPath('data.decision', 'allow');
})->with(StaffRole::cases());

it('accepts the code from the step before, for a phone a little behind', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    scan(codeOnThePhone($this->credential, stepsAgo: 1), $this->mainBranch)->assertJsonPath('data.decision', 'allow');
});

it('denies a code scanned a second time, so a screenshot works only once', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);
    $code = codeOnThePhone($this->credential);

    scan($code, $this->mainBranch)->assertJsonPath('data.decision', 'allow');
    scan($code, $this->mainBranch)
        ->assertOk()
        ->assertJsonPath('data.decision', 'deny')
        ->assertJsonPath('data.reason_code', 'CODE_ALREADY_USED')
        ->assertJsonPath('data.member.full_name', 'Hana Girma');
});

it('denies an older code once a newer one was scanned', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    scan(codeOnThePhone($this->credential), $this->mainBranch)->assertJsonPath('data.decision', 'allow');
    scan(codeOnThePhone($this->credential, stepsAgo: 1), $this->mainBranch)->assertJsonPath('data.reason_code', 'CODE_ALREADY_USED');
});

it('denies a code more than a step old', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    scan(codeOnThePhone($this->credential, stepsAgo: 2), $this->mainBranch)
        ->assertJsonPath('data.decision', 'deny')
        ->assertJsonPath('data.reason_code', 'CODE_EXPIRED');
});

it('denies the code of a revoked credential', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);
    $this->credential->revoke(CredentialRevocation::Replaced);

    scan(codeOnThePhone($this->credential), $this->mainBranch)
        ->assertJsonPath('data.decision', 'deny')
        ->assertJsonPath('data.reason_code', 'CREDENTIAL_REVOKED');
});

it('denies a code that is not right, without saying whose it is', function (Closure $payload) {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    scan($payload($this), $this->mainBranch)
        ->assertOk()
        ->assertJsonPath('data.decision', 'deny')
        ->assertJsonPath('data.reason_code', 'CREDENTIAL_INVALID')
        ->assertJsonPath('data.member', null);

    expect(CheckIn::query()->sole())
        ->result->toBe(CheckInResult::Denied)
        ->member_id->toBeNull();
})->with([
    'garbage' => fn () => 'hello',
    'a tampered code' => function ($test) {
        $code = codeOnThePhone($test->credential);

        return substr($code, 0, -1).(str_ends_with($code, '0') ? '1' : '0');
    },
    'an unknown credential' => fn () => 'GQ1:01K7Z3M6Q8V2N4R5T9W1X0Y2AB:'.Gq1::stepAt(CarbonImmutable::now()->getTimestamp()).':0123456789ABCDEF',
    'another gym\'s credential' => fn () => codeOnThePhone(AccessCredential::factory()->create()),
]);

it('denies a member whose membership cannot let them in today', function (Closure $arrange, string $reasonCode) {
    actingAsStaff($this->gym, StaffRole::Receptionist);
    $arrange($this);

    scan(codeOnThePhone($this->credential), $this->mainBranch)
        ->assertJsonPath('data.decision', 'deny')
        ->assertJsonPath('data.reason_code', $reasonCode);
})->with([
    'no membership' => [fn ($test) => $test->membership->delete(), 'MEMBERSHIP_NONE'],
    'a cancelled membership' => [fn ($test) => $test->membership->forceFill(['cancelled_at' => now(), 'cancelled_by_staff_id' => $test->membership->sold_by_staff_id, 'cancellation_reason' => 'Moved away.'])->save(), 'MEMBERSHIP_NONE'],
    'a membership starting next week' => [fn ($test) => $test->membership->forceFill(['starts_on' => '2026-10-31', 'ends_on' => '2026-11-30'])->save(), 'MEMBERSHIP_NONE'],
    'an expired membership' => [fn ($test) => $test->membership->forceFill(['starts_on' => '2026-09-01', 'ends_on' => '2026-10-23'])->save(), 'MEMBERSHIP_EXPIRED'],
    'a frozen membership' => [fn ($test) => MembershipFreeze::factory()->for($test->membership)->create(), 'MEMBERSHIP_FROZEN'],
    'a membership for another branch' => [function ($test) {
        $test->membership->forceFill(['valid_at_all_branches' => false])->save();
        $test->membership->branches()->attach($test->boleBranch->id, ['organization_id' => $test->gym->id]);
    }, 'BRANCH_NOT_ALLOWED'],
]);

it('lets a branch-only membership in at its own branch', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);
    $this->membership->forceFill(['valid_at_all_branches' => false])->save();
    $this->membership->branches()->attach($this->mainBranch->id, ['organization_id' => $this->gym->id]);

    scan(codeOnThePhone($this->credential), $this->mainBranch)->assertJsonPath('data.decision', 'allow');
});

it('denies a second check-in within 5 minutes, and lets the member in after', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    scan(codeOnThePhone($this->credential), $this->mainBranch)->assertJsonPath('data.decision', 'allow');

    $this->travel(2)->minutes();
    scan(codeOnThePhone($this->credential), $this->mainBranch)->assertJsonPath('data.reason_code', 'ALREADY_CHECKED_IN');

    $this->travel(4)->minutes();
    scan(codeOnThePhone($this->credential), $this->mainBranch)->assertJsonPath('data.decision', 'allow');
});

it('answers a retried scan with the first answer, without tripping the replay check', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);
    $code = codeOnThePhone($this->credential);

    $first = scan($code, $this->mainBranch, 'scan-7f3a')->assertJsonPath('data.decision', 'allow');
    scan($code, $this->mainBranch, 'scan-7f3a')
        ->assertJsonPath('data.decision', 'allow')
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(CheckIn::query()->count())->toBe(1);
});

it('refuses a scan at a branch the staff member does not work at', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->boleBranch);

    scan(codeOnThePhone($this->credential), $this->mainBranch)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_id' => 'Choose a branch of this gym that you work at.']);

    expect(CheckIn::query()->count())->toBe(0);
});

it('uses the only branch a staff member works at when none is given', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->boleBranch);

    $this->postJson(route('api.v1.organizations.check-ins.scan', ['organization' => 'demo-gym']), [
        'payload' => codeOnThePhone($this->credential),
    ])->assertOk()->assertJsonPath('data.branch.name', 'Bole Branch');
});

it('checks a member in at the desk under the same rules', function () {
    $receptionist = actingAsStaff($this->gym, StaffRole::Receptionist);
    $url = route('api.v1.organizations.members.check-ins.store', ['organization' => 'demo-gym', 'member' => $this->member]);

    $this->postJson($url, ['branch_id' => $this->mainBranch->id])
        ->assertOk()
        ->assertJsonPath('data.decision', 'allow')
        ->assertJsonPath('data.method', 'desk')
        ->assertJsonPath('data.checked_in_by.id', $receptionist->id);

    $this->postJson($url, ['branch_id' => $this->mainBranch->id])
        ->assertOk()
        ->assertJsonPath('data.reason_code', 'ALREADY_CHECKED_IN');
});

it('does not check in a member of another gym at the desk', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);
    $stranger = Member::factory()->create();

    $this->postJson(route('api.v1.organizations.members.check-ins.store', ['organization' => 'demo-gym', 'member' => $stranger->id]), [
        'branch_id' => $this->mainBranch->id,
    ])->assertNotFound();
});

it('lists the gym\'s check-ins, newest first, with filters', function () {
    actingAsStaff($this->gym, StaffRole::Manager);
    $other = Member::factory()->for($this->mainBranch, 'homeBranch')->create();
    $yesterday = CheckIn::factory()->for($this->member)->for($this->mainBranch)->create(['checked_in_at' => now()->subDay()]);
    $earlier = CheckIn::factory()->for($this->member)->for($this->boleBranch)->create(['checked_in_at' => now()->subHour()]);
    $denied = CheckIn::factory()->for($other)->for($this->mainBranch)->denied(CheckInDenial::MembershipNone)->create();
    CheckIn::factory()->create();
    $list = route('api.v1.organizations.check-ins.index', ['organization' => 'demo-gym']);

    $this->getJson($list)
        ->assertOk()
        ->assertJsonPath('data.*.id', [$denied->id, $earlier->id, $yesterday->id])
        ->assertJsonPath('data.0.decision', 'deny')
        ->assertJsonPath('data.0.reason_code', 'MEMBERSHIP_NONE');
    $this->getJson($list.'?on=2026-10-24')->assertJsonPath('data.*.id', [$denied->id, $earlier->id]);
    $this->getJson($list.'?branch_id='.$this->boleBranch->id)->assertJsonPath('data.*.id', [$earlier->id]);
    $this->getJson($list.'?result=denied')->assertJsonPath('data.*.id', [$denied->id]);
    $this->getJson($list.'?member_id='.$this->member->id)->assertJsonPath('data.*.id', [$earlier->id, $yesterday->id]);
});
