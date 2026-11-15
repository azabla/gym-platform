<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);
    $main = Branch::factory()->for($this->gym)->create();
    $this->bole = Branch::factory()->for($this->gym)->create();

    // Two memberships at Main and one at Bole end within the week; one at Main ends later.
    foreach ([[$main, '2026-11-17'], [$main, '2026-11-20'], [$this->bole, '2026-11-18'], [$main, '2026-12-30']] as [$branch, $endsOn]) {
        $member = Member::factory()->for($branch, 'homeBranch')->create();
        Membership::factory()->for($member)->create(['starts_on' => '2026-11-01', 'ends_on' => $endsOn]);
    }

    $this->owner = Staff::factory()->for($this->gym)->owner()->create();
    $this->boleReceptionist = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Receptionist]);
    $this->boleReceptionist->assignToBranches($this->bole);
    $this->accountant = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Accountant]);
});

function sendMorningListAt(string $time): void
{
    test()->travelTo(CarbonImmutable::parse($time, 'Africa/Addis_Ababa'));
    test()->artisan('notifications:send-staff-morning-list')->assertSuccessful();
}

it('tells each owner, manager and receptionist how many members to call at their branches, from 08:00', function () {
    sendMorningListAt('2026-11-15 07:30');

    expect($this->owner->notifications()->count())->toBe(0);

    sendMorningListAt('2026-11-15 08:05');

    expect($this->owner->notifications()->sole()->data['details'])->toBe(['count' => 3])
        ->and($this->boleReceptionist->notifications()->sole()->data['details'])->toBe(['count' => 1])
        ->and($this->accountant->notifications()->count())->toBe(0);
});

it('sends the list once a day, and again the next day', function () {
    sendMorningListAt('2026-11-15 08:05');
    sendMorningListAt('2026-11-15 11:00');

    expect($this->owner->notifications()->count())->toBe(1);

    sendMorningListAt('2026-11-16 08:05');

    expect($this->owner->notifications()->count())->toBe(2);
});

it('sends nothing when there is no one to call', function () {
    Membership::query()->acrossOrganizations()->where('ends_on', '<', '2026-12-01')->update(['ends_on' => '2026-12-31']);

    sendMorningListAt('2026-11-15 08:05');

    expect($this->owner->notifications()->count())->toBe(0);
});
