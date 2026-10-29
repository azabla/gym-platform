<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Actions\SellMembershipAction;
use App\Filament\Staff\Resources\Members\Pages\ViewMember;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create();
    $branch = Branch::factory()->for($this->gym)->create();
    $this->monthly = Plan::factory()->for($this->gym)->create([
        'name' => 'Monthly',
        'price' => Money::of('2,500'),
        'duration_count' => 1,
        'duration_unit' => DurationUnit::Month,
    ]);
    $this->hana = Member::factory()->for($branch, 'homeBranch')->create();
});

it('lets a receptionist sell a plan from the first free day', function () {
    $receptionist = actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    Livewire::test(ViewMember::class, ['record' => $this->hana->getRouteKey()])
        ->mountAction(SellMembershipAction::class)
        ->assertSchemaStateSet(['starts_on' => '2026-10-24'])
        ->fillForm(['plan_id' => $this->monthly->id])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Monthly sold, from 24 October 2026.');

    $membership = Membership::query()->sole();

    expect($membership->starts_on->toDateString())->toBe('2026-10-24')
        ->and($membership->ends_on->toDateString())->toBe('2026-11-23')
        ->and($membership->price)->toEqual(Money::of('2,500'))
        ->and($membership->sold_by_staff_id)->toBe($receptionist->id);
});

it('refuses a sale that overlaps the member\'s membership, naming the first free day', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    Membership::factory()->for($this->hana)->for($this->monthly)->create(['starts_on' => '2026-10-10', 'ends_on' => '2026-11-09']);

    Livewire::test(ViewMember::class, ['record' => $this->hana->getRouteKey()])
        ->callAction(SellMembershipAction::class, ['plan_id' => $this->monthly->id, 'starts_on' => '2026-10-24'])
        ->assertNotified('The member already has a membership from 10 October 2026 to 9 November 2026. The first free start date is 10 November 2026.');

    expect(Membership::query()->count())->toBe(1);
});

it('keeps discounts and past start dates to owners and managers', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    Livewire::test(ViewMember::class, ['record' => $this->hana->getRouteKey()])
        ->callAction(SellMembershipAction::class, ['plan_id' => $this->monthly->id, 'starts_on' => '2026-10-20'])
        ->assertHasFormErrors(['starts_on']);

    expect(Membership::query()->count())->toBe(0);

    // The discount field isn't shown to a receptionist, so a discount sent anyway is ignored.
    Livewire::test(ViewMember::class, ['record' => $this->hana->getRouteKey()])
        ->callAction(SellMembershipAction::class, ['plan_id' => $this->monthly->id, 'discount' => '10%', 'discount_reason' => 'Friend'])
        ->assertHasNoFormErrors();

    expect(Membership::query()->sole()->price)->toEqual(Money::of('2,500'));
});

it('lets a manager sell at a discount and from a past day', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager);

    Livewire::test(ViewMember::class, ['record' => $this->hana->getRouteKey()])
        ->callAction(SellMembershipAction::class, [
            'plan_id' => $this->monthly->id,
            'starts_on' => '2026-10-20',
            'discount' => '10%',
            'discount_reason' => 'Student',
        ])
        ->assertHasNoFormErrors();

    $membership = Membership::query()->sole();

    expect($membership->starts_on->toDateString())->toBe('2026-10-20')
        ->and($membership->price)->toEqual(Money::of('2,250'))
        ->and($membership->discount_reason)->toBe('Student');
});

it('refuses a discount bigger than the price', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(ViewMember::class, ['record' => $this->hana->getRouteKey()])
        ->callAction(SellMembershipAction::class, [
            'plan_id' => $this->monthly->id,
            'discount' => '3,000',
            'discount_reason' => 'Mistake',
        ])
        ->assertHasFormErrors(['discount']);
});

it('hides the sale from trainers', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Trainer);

    Livewire::test(ViewMember::class, ['record' => $this->hana->getRouteKey()])
        ->assertActionHidden(SellMembershipAction::class);
});
