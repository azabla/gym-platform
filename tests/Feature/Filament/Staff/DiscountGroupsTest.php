<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Memberships\Models\MemberDiscount;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Actions\DiscountGroupAction;
use App\Filament\Staff\Actions\SellMembershipAction;
use App\Filament\Staff\Resources\DiscountGroups\DiscountGroupResource;
use App\Filament\Staff\Resources\DiscountGroups\Pages\CreateDiscountGroup;
use App\Filament\Staff\Resources\DiscountGroups\Pages\EditDiscountGroup;
use App\Filament\Staff\Resources\DiscountGroups\Pages\ListDiscountGroups;
use App\Filament\Staff\Resources\Members\Pages\ViewMember;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);
    $branch = Branch::factory()->for($this->gym)->create();
    $this->monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->tigist = Member::factory()->for($branch, 'homeBranch')->create();
    $this->student = DiscountGroup::factory()->for($this->gym)->create(['name' => 'Student', 'discount' => '10%']);
});

it('lets a manager add, list, change and archive groups', function () {
    $manager = actingAsStaffInPanel($this->gym, StaffRole::Manager);

    Livewire::test(CreateDiscountGroup::class)
        ->fillForm(['name' => 'Staff family', 'discount' => '50%'])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateDiscountGroup::class)
        ->fillForm(['name' => 'student', 'discount' => 'half'])
        ->call('create')
        ->assertHasFormErrors(['name', 'discount']);

    $staffFamily = DiscountGroup::query()->where('name', 'Staff family')->sole();
    expect($staffFamily->created_by_staff_id)->toBe($manager->id);

    Livewire::test(ListDiscountGroups::class)->assertCanSeeTableRecords([$this->student, $staffFamily]);

    Livewire::test(EditDiscountGroup::class, ['record' => $staffFamily->getRouteKey()])
        ->fillForm(['discount' => '40%'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->callAction('archive')
        ->assertNotified('Discount group archived.');

    expect($staffFamily->refresh()->discount)->toBe('40%')
        ->and($staffFamily->trashed())->toBeTrue();
});

it('keeps groups from receptionists', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    $this->get(DiscountGroupResource::getUrl('index', ['tenant' => $this->gym]))->assertForbidden();

    Livewire::test(ViewMember::class, ['record' => $this->tigist->getRouteKey()])
        ->assertActionHidden(DiscountGroupAction::class);
});

it('puts a member in a group from their page, and takes them out', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(ViewMember::class, ['record' => $this->tigist->getRouteKey()])
        ->callAction(DiscountGroupAction::class, ['discount_group_id' => $this->student->id, 'until' => '2027-06-30'])
        ->assertNotified('In the Student group.')
        ->assertSee('Student, 10% off, until 30 Jun 2027');

    expect(MemberDiscount::groupFor($this->tigist, CarbonImmutable::parse('2026-12-20'))?->is($this->student))->toBeTrue();

    Livewire::test(ViewMember::class, ['record' => $this->tigist->getRouteKey()])
        ->callAction(DiscountGroupAction::class, ['remove' => true])
        ->assertNotified('Taken out of their discount group.');

    expect(MemberDiscount::query()->count())->toBe(0);
});

it('sells to a member in a group at the group\'s price', function () {
    $receptionist = actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    MemberDiscount::assign($this->tigist, $this->student, null, $receptionist);

    Livewire::test(ViewMember::class, ['record' => $this->tigist->getRouteKey()])
        ->callAction(SellMembershipAction::class, ['plan_id' => $this->monthly->id])
        ->assertHasNoFormErrors();

    $membership = Membership::query()->sole();
    expect($membership->price)->toEqual(Money::of('2,250'))
        ->and($membership->discount_reason)->toBe('Discount group Student');
});
