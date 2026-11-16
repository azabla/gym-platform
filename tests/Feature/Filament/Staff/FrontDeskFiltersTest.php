<?php

declare(strict_types=1);

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Enums\CheckInMethod;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Members\Enums\Gender;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Memberships\Models\MemberDiscount;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Staff\Resources\CheckIns\Pages\ListCheckIns;
use App\Filament\Staff\Resources\Members\Pages\ListMembers;
use App\Filament\Staff\Resources\Memberships\Pages\ListMemberships;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = actingAsOrganization(Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']));
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $this->monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->yearly = Plan::factory()->for($this->gym)->create(['name' => 'Yearly', 'price' => Money::of('24,000')]);

    $this->active = Member::factory()->for($this->main, 'homeBranch')->create(['full_name' => 'Active Member', 'gender' => Gender::Female]);
    $this->ending = Member::factory()->for($this->main, 'homeBranch')->create(['full_name' => 'Ending Member', 'gender' => Gender::Male]);
    $this->expired = Member::factory()->for($this->bole, 'homeBranch')->create(['full_name' => 'Expired Member']);
    $this->never = Member::factory()->for($this->bole, 'homeBranch')->create(['full_name' => 'New Member']);

    $this->activeMembership = Membership::factory()->for($this->active)->for($this->yearly)->create(['starts_on' => '2026-11-01', 'ends_on' => '2027-10-31', 'sold_in_app' => true, 'sold_by_staff_id' => null]);
    $this->endingMembership = Membership::factory()->for($this->ending)->for($this->monthly)->create(['starts_on' => '2026-10-24', 'ends_on' => '2026-11-23']);
    $this->expiredMembership = Membership::factory()->for($this->expired)->for($this->monthly)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
});

it('filters members by their membership today, discount group and gender', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $student = DiscountGroup::factory()->for($this->gym)->create(['name' => 'Student']);
    MemberDiscount::assign($this->ending, $student, null, Staff::query()->firstOrFail());

    $list = fn () => Livewire::test(ListMembers::class);

    $list()->filterTable('membership_today', 'active')
        ->assertCanSeeTableRecords([$this->active, $this->ending])->assertCanNotSeeTableRecords([$this->expired, $this->never]);
    $list()->filterTable('membership_today', 'ending')
        ->assertCanSeeTableRecords([$this->ending])->assertCanNotSeeTableRecords([$this->active]);
    $list()->filterTable('membership_today', 'expired')
        ->assertCanSeeTableRecords([$this->expired])->assertCanNotSeeTableRecords([$this->never, $this->active]);
    $list()->filterTable('membership_today', 'never')
        ->assertCanSeeTableRecords([$this->never])->assertCanNotSeeTableRecords([$this->expired]);
    $list()->filterTable('discount_group', $student->id)
        ->assertCanSeeTableRecords([$this->ending])->assertCanNotSeeTableRecords([$this->active]);
    $list()->filterTable('gender', Gender::Female->value)
        ->assertCanSeeTableRecords([$this->active])->assertCanNotSeeTableRecords([$this->ending]);
});

it('shows each member\'s membership today on the list', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(ListMembers::class)
        ->assertTableColumnStateSet('membership_today', 'Active · Yearly', $this->active)
        ->assertTableColumnStateSet('membership_today', 'Expired · Monthly', $this->expired)
        ->assertTableColumnStateSet('membership_today', 'None', $this->never);
});

it('filters memberships by plan, home branch, ending soon, where sold and discount', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $withCode = Membership::factory()->for($this->never)->for($this->monthly)->create([
        'starts_on' => '2026-11-20', 'ends_on' => '2026-12-19',
        'list_price' => Money::of('2,500'), 'price' => Money::of('2,000'), 'discount_reason' => 'Promo code NEWYEAR',
        'promo_code_id' => PromoCode::factory()->for($this->gym)->create()->id,
    ]);

    $list = fn () => Livewire::test(ListMemberships::class);

    $list()->filterTable('plan', $this->yearly->id)
        ->assertCanSeeTableRecords([$this->activeMembership])->assertCanNotSeeTableRecords([$this->endingMembership]);
    $list()->filterTable('home_branch', $this->bole->id)
        ->assertCanSeeTableRecords([$this->expiredMembership, $withCode])->assertCanNotSeeTableRecords([$this->activeMembership]);
    $list()->filterTable('ending_within', '7')
        ->assertCanSeeTableRecords([$this->endingMembership])->assertCanNotSeeTableRecords([$this->activeMembership, $withCode]);
    $list()->filterTable('sold_in_app', true)
        ->assertCanSeeTableRecords([$this->activeMembership])->assertCanNotSeeTableRecords([$this->endingMembership]);
    $list()->filterTable('discount', 'promo_code')
        ->assertCanSeeTableRecords([$withCode])->assertCanNotSeeTableRecords([$this->activeMembership]);
    $list()->filterTable('discount', 'none')
        ->assertCanSeeTableRecords([$this->activeMembership])->assertCanNotSeeTableRecords([$withCode]);
});

it('filters check-ins by a range of the gym\'s days, reason and method', function () {
    $staff = actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $at = fn (string $local) => CarbonImmutable::parse($local, 'Africa/Addis_Ababa')->utc();
    $earlier = CheckIn::factory()->for($this->active)->for($this->main)->create(['checked_in_at' => $at('2026-11-10 07:00'), 'checked_in_by_staff_id' => $staff->id]);
    $later = CheckIn::factory()->for($this->active)->for($this->main)->create(['checked_in_at' => $at('2026-11-18 01:30'), 'method' => CheckInMethod::Qr, 'checked_in_by_staff_id' => $staff->id]);
    $denied = CheckIn::factory()->for($this->expired)->for($this->bole)->denied(CheckInDenial::MembershipExpired)->create(['checked_in_at' => $at('2026-11-19 18:00'), 'checked_in_by_staff_id' => $staff->id]);

    $list = fn () => Livewire::test(ListCheckIns::class);

    $list()->filterTable('between', ['from' => '2026-11-18', 'to' => '2026-11-19'])
        ->assertCanSeeTableRecords([$later, $denied])->assertCanNotSeeTableRecords([$earlier]);
    $list()->filterTable('reason_code', CheckInDenial::MembershipExpired->value)
        ->assertCanSeeTableRecords([$denied])->assertCanNotSeeTableRecords([$earlier, $later]);
    $list()->filterTable('method', CheckInMethod::Qr->value)
        ->assertCanSeeTableRecords([$later])->assertCanNotSeeTableRecords([$earlier]);
});

it('filters announcements by status and audience', function () {
    $owner = actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $sent = Announcement::factory()->sent(12)->create(['organization_id' => $this->gym->id, 'posted_by_staff_id' => $owner->id]);
    $scheduledAtBole = Announcement::factory()->create(['organization_id' => $this->gym->id, 'posted_by_staff_id' => $owner->id, 'branch_id' => $this->bole->id]);

    Livewire::test(ListAnnouncements::class)
        ->filterTable('status', 'sent')
        ->assertCanSeeTableRecords([$sent])->assertCanNotSeeTableRecords([$scheduledAtBole]);

    Livewire::test(ListAnnouncements::class)
        ->filterTable('to', (string) $this->bole->id)
        ->assertCanSeeTableRecords([$scheduledAtBole])->assertCanNotSeeTableRecords([$sent]);

    Livewire::test(ListAnnouncements::class)
        ->filterTable('to', 'gym')
        ->assertCanSeeTableRecords([$sent])->assertCanNotSeeTableRecords([$scheduledAtBole]);
});
