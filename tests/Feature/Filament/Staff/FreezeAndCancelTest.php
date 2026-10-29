<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Filament\Staff\Actions\CancelMembershipAction;
use App\Filament\Staff\Actions\EndFreezeAction;
use App\Filament\Staff\Actions\FreezeMembershipAction;
use App\Filament\Staff\Resources\Memberships\Pages\ViewMembership;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create();
    $member = Member::factory()->for(Branch::factory()->for($this->gym), 'homeBranch')->create();
    $this->membership = Membership::factory()->for($member)->create([
        'starts_on' => '2026-10-10',
        'ends_on' => '2026-11-09',
        'freeze_days_allowed' => 7,
    ]);
});

function viewMembership(Membership $membership): mixed
{
    return Livewire::test(ViewMembership::class, ['record' => $membership->getRouteKey()]);
}

it('lets a receptionist freeze a membership, moving its end date later', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    viewMembership($this->membership)
        ->callAction(FreezeMembershipAction::class, ['ends_on' => '2026-10-30', 'reason' => 'Travelling.'])
        ->assertHasNoFormErrors()
        ->assertNotified('Frozen. It now ends on 16 November 2026.');

    expect(MembershipFreeze::query()->sole()->starts_on->toDateString())->toBe('2026-10-24');
});

it('keeps a receptionist within the freeze days, but not a manager', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    viewMembership($this->membership)
        ->callAction(FreezeMembershipAction::class, ['ends_on' => '2026-11-03', 'reason' => 'Surgery.'])
        ->assertNotified('This membership can be frozen for 7 more days.');

    expect(MembershipFreeze::query()->count())->toBe(0);

    actingAsStaffInPanel($this->gym, StaffRole::Manager);

    viewMembership($this->membership)
        ->callAction(FreezeMembershipAction::class, ['ends_on' => '2026-11-03', 'reason' => 'Surgery.'])
        ->assertHasNoFormErrors();

    expect(MembershipFreeze::query()->count())->toBe(1);
});

it('ends a freeze early when the member is back', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    $this->membership->freeze(CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-26'), 'Travelling.', $this->membership->soldBy);

    viewMembership($this->membership)
        ->callAction(EndFreezeAction::class)
        ->assertNotified('Freeze ended. It now ends on 13 November 2026.');

    expect(MembershipFreeze::query()->sole()->ends_on->toDateString())->toBe('2026-10-23');
});

it('lets a manager cancel, but not a receptionist', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    viewMembership($this->membership)->assertActionHidden(CancelMembershipAction::class);

    actingAsStaffInPanel($this->gym, StaffRole::Manager);

    viewMembership($this->membership)
        ->callAction(CancelMembershipAction::class, ['reason' => 'Moved away.'])
        ->assertNotified('Membership cancelled.');

    expect($this->membership->refresh()->cancelled_at)->not->toBeNull()
        ->and($this->membership->cancellation_reason)->toBe('Moved away.');
});

it('refuses to cancel a membership that has ended', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $this->membership->forceFill(['ends_on' => '2026-10-20'])->save();

    viewMembership($this->membership)
        ->callAction(CancelMembershipAction::class, ['reason' => 'Moved away.'])
        ->assertNotified('This membership has already ended.');

    expect($this->membership->refresh()->cancelled_at)->toBeNull();
});
