<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Filament\Staff\Resources\CheckIns\Pages\ListCheckIns;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);
    $branch = Branch::factory()->for($this->gym)->create();
    $hana = Member::factory()->for($branch, 'homeBranch')->create(['full_name' => 'Hana Girma']);

    // 01:30 on 24 October where the gym is, still the 23rd in UTC.
    $this->earlyOn24th = CheckIn::factory()->for($hana)->for($branch)->create(['checked_in_at' => CarbonImmutable::parse('2026-10-23 22:30:00', 'UTC')]);
    $this->on23rd = CheckIn::factory()->for($hana)->for($branch)->create(['checked_in_at' => CarbonImmutable::parse('2026-10-23 08:00:00', 'UTC')]);
    $this->denied = CheckIn::factory()->for($hana)->for($branch)->denied(CheckInDenial::MembershipExpired)->create(['checked_in_at' => CarbonImmutable::parse('2026-10-24 07:00:00', 'UTC')]);
});

it('shows the check-ins of a day where the gym is', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Trainer);

    Livewire::test(ListCheckIns::class)
        ->filterTable('on', ['on' => '2026-10-24'])
        ->assertCanSeeTableRecords([$this->earlyOn24th, $this->denied])
        ->assertCanNotSeeTableRecords([$this->on23rd])
        ->assertSee('24 Oct 2026, 01:30');
});

it('shows who was turned away, and why', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    Livewire::test(ListCheckIns::class)
        ->filterTable('result', 'denied')
        ->assertCanSeeTableRecords([$this->denied])
        ->assertCanNotSeeTableRecords([$this->earlyOn24th, $this->on23rd])
        ->assertSee('Membership expired');
});

it('marks scans made offline, and lists the ones the server would have decided otherwise', function () {
    $this->on23rd->forceFill(['offline' => true])->save();
    $this->earlyOn24th->forceFill(['offline' => true, 'server_disagrees' => true, 'server_reason_code' => CheckInDenial::MembershipExpired])->save();
    actingAsStaffInPanel($this->gym, StaffRole::Manager);

    Livewire::test(ListCheckIns::class)
        ->filterTable('offline', true)
        ->assertCanSeeTableRecords([$this->on23rd, $this->earlyOn24th])
        ->assertCanNotSeeTableRecords([$this->denied])
        ->assertSee('Offline');

    Livewire::test(ListCheckIns::class)
        ->filterTable('needs_review', true)
        ->assertCanSeeTableRecords([$this->earlyOn24th])
        ->assertCanNotSeeTableRecords([$this->on23rd, $this->denied])
        ->assertSee('Needs review')
        ->assertSee('The server says: Membership expired');
});
