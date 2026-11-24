<?php

declare(strict_types=1);

use App\Domains\Attendance\Credentials\Gq1;
use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Enums\CredentialRevocation;
use App\Domains\Attendance\Models\AccessCredential;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    // Saturday 24 October 2026, 12:00 in Addis Ababa: the scanner is back online.
    $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00', 'Africa/Addis_Ababa'));
    $this->pushes = fakePushes();

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->hana = Member::factory()->for($this->main, 'homeBranch')->create(['full_name' => 'Hana Girma']);
    $this->membership = Membership::factory()->for($this->hana)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-11-30']);
    $this->credential = AccessCredential::factory()->for($this->hana)->create();
});

/**
 * What the member's phone showed at the time, where the gym is.
 */
function codeShownAt(AccessCredential $credential, string $localTime): string
{
    $step = Gq1::stepAt(CarbonImmutable::parse($localTime, 'Africa/Addis_Ababa')->getTimestamp());

    return Gq1::payload($credential->rawSecret(), $credential->publicId(), $step);
}

/**
 * One scan as the staff app sends it.
 *
 * @return array<string, mixed>
 */
function offlineScan(string $payload, Branch $branch, string $localTime, string $decision = 'allow', ?string $reason = null, ?string $id = null): array
{
    return [
        'id' => $id ?? (string) Str::uuid(),
        'payload' => $payload,
        'branch_id' => $branch->id,
        'scanned_at' => CarbonImmutable::parse($localTime, 'Africa/Addis_Ababa')->toIso8601String(),
        'decision' => $decision,
        'reason_code' => $reason,
    ];
}

function sendOfflineScans(array ...$scans): TestResponse
{
    return test()->postJson(route('api.v1.organizations.check-ins.offline.store', ['organization' => 'demo-gym']), ['scans' => $scans]);
}

it('records a scan made offline at the time it happened, and a retry changes nothing', function () {
    $receptionist = actingAsStaff($this->gym, StaffRole::Receptionist);
    $scan = offlineScan(codeShownAt($this->credential, '2026-10-24 08:30'), $this->main, '2026-10-24 08:30');

    sendOfflineScans($scan)
        ->assertOk()
        ->assertJsonPath('data.0.status', 'recorded')
        ->assertJsonPath('data.0.server_disagrees', false)
        ->assertJsonPath('data.0.server_reason_code', null);

    $checkIn = CheckIn::query()->sole();

    expect($checkIn->offline)->toBeTrue()
        ->and($checkIn->result)->toBe(CheckInResult::Allowed)
        ->and($checkIn->checked_in_at->toIso8601String())->toBe('2026-10-24T05:30:00+00:00')
        ->and($checkIn->checked_in_by_staff_id)->toBe($receptionist->id)
        ->and($checkIn->membership_id)->toBe($this->membership->id)
        ->and($this->credential->refresh()->last_used_step)->toBe($checkIn->code_step);

    sendOfflineScans($scan)->assertJsonPath('data.0.status', 'already_recorded')->assertJsonPath('data.0.check_in_id', $checkIn->id);

    expect(CheckIn::query()->count())->toBe(1);
});

it('refuses scans that don\'t hold up, and records the rest of the batch', function () {
    actingAsStaff($this->gym, StaffRole::Trainer);
    $otherGymsCredential = AccessCredential::factory()->create();
    $good = codeShownAt($this->credential, '2026-10-24 09:00');

    sendOfflineScans(
        offlineScan($good, $this->main, '2026-10-24 09:00'),
        offlineScan('GQ1:'.$this->credential->publicId().':123:0000000000000000', $this->main, '2026-10-24 09:10'),
        offlineScan(codeShownAt($this->credential, '2026-10-24 09:20'), $this->main, '2026-10-24 11:00'),
        offlineScan(codeShownAt($otherGymsCredential, '2026-10-24 09:30'), $this->main, '2026-10-24 09:30'),
        offlineScan(codeShownAt($this->credential, '2026-10-24 12:30'), $this->main, '2026-10-24 12:30'),
        offlineScan(codeShownAt($this->credential, '2026-10-20 09:00'), $this->main, '2026-10-20 09:00'),
    )
        ->assertOk()
        ->assertJsonPath('data.*.status', ['recorded', 'refused', 'refused', 'refused', 'refused', 'refused'])
        ->assertJsonPath('data.1.message', 'The code doesn\'t match the time of the scan.')
        ->assertJsonPath('data.2.message', 'The code doesn\'t match the time of the scan.')
        ->assertJsonPath('data.3.message', 'This isn\'t a QR code of this gym.')
        ->assertJsonPath('data.4.message', 'The scan is dated in the future. Check the scanner\'s clock.')
        ->assertJsonPath('data.5.message', 'The scan is more than 3 days old.');

    expect(CheckIn::query()->count())->toBe(1);
});

it('flags a scan the server would have decided otherwise, keeping what the door did', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);
    // The membership was shortened to end on the 23rd after the door list was downloaded.
    $this->membership->forceFill(['ends_on' => '2026-10-23'])->save();

    sendOfflineScans(offlineScan(codeShownAt($this->credential, '2026-10-24 08:30'), $this->main, '2026-10-24 08:30'))
        ->assertJsonPath('data.0.status', 'recorded')
        ->assertJsonPath('data.0.server_disagrees', true)
        ->assertJsonPath('data.0.server_reason_code', 'MEMBERSHIP_EXPIRED');

    expect(CheckIn::query()->sole()->result)->toBe(CheckInResult::Allowed);
});

it('flags the same code let in twice by two scanners, and a credential revoked by then', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);
    $code = codeShownAt($this->credential, '2026-10-24 08:30');

    sendOfflineScans(
        offlineScan($code, $this->main, '2026-10-24 08:30'),
        offlineScan($code, $this->main, '2026-10-24 08:30'),
    )->assertJsonPath('data.*.server_reason_code', [null, 'CODE_ALREADY_USED']);

    $this->credential->forceFill(['revoked_at' => CarbonImmutable::parse('2026-10-24 09:00', 'Africa/Addis_Ababa')->utc(), 'revoked_reason' => CredentialRevocation::SignedOut])->save();

    sendOfflineScans(offlineScan(codeShownAt($this->credential, '2026-10-24 10:00'), $this->main, '2026-10-24 10:00'))
        ->assertJsonPath('data.0.server_reason_code', 'CREDENTIAL_REVOKED');
});

it('agrees with a door that turned the member away for the same reason', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    sendOfflineScans(
        offlineScan(codeShownAt($this->credential, '2026-10-24 08:30'), $this->main, '2026-10-24 08:30'),
        offlineScan(codeShownAt($this->credential, '2026-10-24 08:32'), $this->main, '2026-10-24 08:32', 'deny', 'ALREADY_CHECKED_IN'),
    )
        ->assertJsonPath('data.*.server_disagrees', [false, false]);

    expect(CheckIn::query()->orderBy('checked_in_at')->pluck('result')->all())->toBe([CheckInResult::Allowed, CheckInResult::Denied]);
});

it('still says "one visit left" for an uploaded scan, but not "refused"', function () {
    $this->membership->forceFill(['visit_limit' => 2])->save();
    actingAsStaff($this->gym, StaffRole::Receptionist);

    sendOfflineScans(
        offlineScan(codeShownAt($this->credential, '2026-10-24 08:30'), $this->main, '2026-10-24 08:30'),
        offlineScan(codeShownAt($this->credential, '2026-10-24 08:31'), $this->main, '2026-10-24 08:31', 'deny', 'ALREADY_CHECKED_IN'),
    );

    expect($this->hana->notifications()->pluck('type')->all())->toBe(['visits_running_low']);
});

it('refuses a scan at a branch the staff member doesn\'t work at, and a deny without a reason', function () {
    $bole = Branch::factory()->for($this->gym)->create();
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->main);

    sendOfflineScans(offlineScan(codeShownAt($this->credential, '2026-10-24 08:30'), $bole, '2026-10-24 08:30'))
        ->assertJsonPath('data.0.status', 'refused')
        ->assertJsonPath('data.0.message', 'You don\'t work at this branch.');

    sendOfflineScans(offlineScan(codeShownAt($this->credential, '2026-10-24 08:40'), $this->main, '2026-10-24 08:40', 'deny'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scans.0.reason_code');
});
