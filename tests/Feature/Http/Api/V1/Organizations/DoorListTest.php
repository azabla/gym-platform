<?php

declare(strict_types=1);

use App\Domains\Attendance\Credentials\Gq1;
use App\Domains\Attendance\DoorList\DoorListSigner;
use App\Domains\Attendance\Enums\CredentialRevocation;
use App\Domains\Attendance\Models\AccessCredential;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\BranchClosure;
use App\Domains\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Saturday 24 October 2026, 10:00 in Addis Ababa (07:00 UTC).
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00', 'Africa/Addis_Ababa'));
    $this->keys = DoorListSigner::generateKeyPair();
    config(['services.door_list.signing_key' => $this->keys['secret_key']]);

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);

    $this->hana = Member::factory()->for($this->main, 'homeBranch')->create(['full_name' => 'Hana Girma']);
    $this->hanasMembership = Membership::factory()->for($this->hana)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-25']);
    $this->hanasCredential = AccessCredential::factory()->for($this->hana)->create();
});

/**
 * Download the door list, check its signature, and read it.
 *
 * @return array<string, mixed>
 */
function downloadDoorList(string $query = ''): array
{
    $data = test()->getJson(route('api.v1.organizations.door-list.show', ['organization' => 'demo-gym']).$query)->assertOk()->json('data');

    expect(sodium_crypto_sign_verify_detached(base64_decode($data['signature']), $data['list'], base64_decode(test()->keys['public_key'])))->toBeTrue();

    return json_decode($data['list'], true);
}

it('gives a signed list that a changed copy fails', function () {
    actingAsStaff($this->gym, StaffRole::Trainer);

    $data = $this->getJson(route('api.v1.organizations.door-list.show', ['organization' => 'demo-gym']))->assertOk()->json('data');
    $tampered = str_replace('Hana Girma', 'Someone Else', $data['list']);

    expect(sodium_crypto_sign_verify_detached(base64_decode($data['signature']), $tampered, base64_decode($this->keys['public_key'])))->toBeFalse();

    $this->getJson(route('api.v1.organizations.door-list.public-key', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.algorithm', 'Ed25519')
        ->assertJsonPath('data.public_key', $this->keys['public_key'])
        ->assertJsonPath('data.key_id', $data['key_id']);
});

it('carries day keys that make the codes the phone shows, for today and tomorrow (UTC)', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $list = downloadDoorList();
    $credential = collect($list['credentials'])->firstWhere('id', $this->hanasCredential->publicId());
    $today = intdiv(CarbonImmutable::now()->getTimestamp(), 86400);
    $step = Gq1::stepAt(CarbonImmutable::now()->getTimestamp());
    $codeFromDayKey = strtoupper(bin2hex(substr(hash_hmac('sha256', "GQ1|{$credential['id']}|{$step}", base64_decode($credential['day_keys'][(string) $today]), true), 0, 8)));

    expect($list['utc_days'])->toBe([$today, $today + 1])
        ->and($list['valid_until'])->toBe('2026-10-26T00:00:00+00:00')
        ->and($list['dates'])->toBe(['2026-10-24', '2026-10-25', '2026-10-26'])
        ->and(array_keys($credential['day_keys']))->toBe([$today, $today + 1])
        ->and($codeFromDayKey)->toBe($this->hanasCredential->codeAt($step))
        ->and($list)->not->toHaveKey('secret')
        ->and(json_encode($list))->not->toContain($this->hanasCredential->secret);
});

it('says what each membership allows on each day', function () {
    $abebe = Member::factory()->for($this->main, 'homeBranch')->create();
    $abebesMembership = Membership::factory()->for($abebe)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-11-30', 'visit_limit' => 10, 'valid_at_all_branches' => false]);
    $abebesMembership->branches()->attach($this->bole->id, ['organization_id' => $this->gym->id]);
    MembershipFreeze::factory()->for($abebesMembership)->create(['starts_on' => '2026-10-26', 'ends_on' => '2026-10-27']);
    AccessCredential::factory()->for($abebe)->create();
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $credentials = collect(downloadDoorList()['credentials'])->keyBy('member.id');

    expect($credentials[$this->hana->id]['days']['2026-10-25'])->toMatchArray(['allowed' => true, 'reason_code' => null, 'branch_ids' => null, 'visits_left' => null])
        ->and($credentials[$this->hana->id]['days']['2026-10-26'])->toMatchArray(['allowed' => false, 'reason_code' => 'MEMBERSHIP_EXPIRED'])
        ->and($credentials[$abebe->id]['days']['2026-10-24'])->toMatchArray(['allowed' => true, 'branch_ids' => [$this->bole->id], 'visits_left' => 10])
        ->and($credentials[$abebe->id]['days']['2026-10-26'])->toMatchArray(['allowed' => false, 'reason_code' => 'MEMBERSHIP_FROZEN']);
});

it('leaves out revoked credentials and members who left', function () {
    $this->hanasCredential->revoke(CredentialRevocation::Replaced);
    $newCredential = AccessCredential::factory()->for($this->hana)->create();
    $gone = Member::factory()->for($this->main, 'homeBranch')->create();
    AccessCredential::factory()->for($gone)->create();
    $gone->delete();
    actingAsStaff($this->gym, StaffRole::Receptionist);

    expect(collect(downloadDoorList()['credentials'])->pluck('id')->all())->toBe([$newCredential->publicId()]);
});

it('gives each of the staff member\'s branches its hours, closures included', function () {
    BranchClosure::factory()->for($this->main)->create(['starts_on' => '2026-10-25', 'ends_on' => '2026-10-25']);
    BranchClosure::factory()->for($this->main)->withHours('08:00', '12:00')->create(['starts_on' => '2026-10-26', 'ends_on' => '2026-10-26']);
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->main);

    $branches = downloadDoorList()['branches'];

    expect(collect($branches)->pluck('name')->all())->toBe(['Main Branch'])
        ->and($branches[0]['hours'])->toBe([
            '2026-10-24' => ['opens_at' => '00:00', 'closes_at' => '24:00'],
            '2026-10-25' => null,
            '2026-10-26' => ['opens_at' => '08:00', 'closes_at' => '12:00'],
        ]);

    $this->getJson(route('api.v1.organizations.door-list.show', ['organization' => 'demo-gym']).'?branch_id='.$this->bole->id)->assertNotFound();
});

it('builds the list in the same number of queries however many members there are', function () {
    $this->hanasMembership->forceFill(['visit_limit' => 8])->save();
    actingAsStaff($this->gym, StaffRole::Owner);

    DB::enableQueryLog();
    downloadDoorList();
    $fewMembers = count(DB::getQueryLog());

    foreach (range(1, 5) as $ignored) {
        $member = Member::factory()->for($this->main, 'homeBranch')->create();
        Membership::factory()->for($member)->create(['visit_limit' => 8]);
        AccessCredential::factory()->for($member)->create();
    }

    DB::flushQueryLog();
    downloadDoorList();

    expect(count(DB::getQueryLog()))->toBe($fewMembers);
});

it('says offline scanning is not set up without a key', function () {
    config(['services.door_list.signing_key' => null]);
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->getJson(route('api.v1.organizations.door-list.show', ['organization' => 'demo-gym']))
        ->assertStatus(503)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'door-list:generate-key'));
});
