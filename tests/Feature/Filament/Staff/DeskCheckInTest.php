<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Filament\Staff\Actions\CheckInAction;
use App\Filament\Staff\Resources\Members\Pages\ListMembers;
use App\Filament\Staff\Resources\Members\Pages\ViewMember;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create();
    $this->branch = Branch::factory()->for($this->gym)->create();
    $this->hana = Member::factory()->for($this->branch, 'homeBranch')->create(['full_name' => 'Hana Girma']);
});

it('lets a member with a valid membership in, at the staff member\'s branch', function () {
    $trainer = actingAsStaffInPanel($this->gym, StaffRole::Trainer, $this->branch);
    Membership::factory()->for($this->hana)->create(['starts_on' => '2026-10-10', 'ends_on' => '2026-11-09']);

    Livewire::test(ViewMember::class, ['record' => $this->hana->getRouteKey()])
        ->callAction(CheckInAction::class)
        ->assertNotified('Hana Girma is let in.');

    $checkIn = CheckIn::query()->sole();

    expect($checkIn->result)->toBe(CheckInResult::Allowed)
        ->and($checkIn->branch_id)->toBe($this->branch->id)
        ->and($checkIn->checked_in_by_staff_id)->toBe($trainer->id);
});

it('turns away a member whose membership has ended, saying why, and keeps the attempt', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    Membership::factory()->for($this->hana)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);

    Livewire::test(ListMembers::class)
        ->callTableAction(CheckInAction::class, $this->hana, ['branch_id' => $this->branch->id])
        ->assertNotified('Hana Girma is turned away.');

    expect(CheckIn::query()->sole()->reason_code)->toBe(CheckInDenial::MembershipExpired);
});
