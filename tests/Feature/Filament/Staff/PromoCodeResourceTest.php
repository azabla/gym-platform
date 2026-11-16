<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Filament\Staff\Resources\PromoCodes\Pages\EditPromoCode;
use App\Filament\Staff\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Filament\Staff\Resources\PromoCodes\PromoCodeResource;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $this->monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->boleMonthly = Plan::factory()->for($this->gym)->create(['name' => 'Bole Monthly', 'price' => Money::of('2,000')]);
    $this->boleMonthly->makeAvailableOnlyAt($this->bole);
});

it('lets the owner add a code for every plan, or only some', function () {
    $owner = actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(CreatePromoCode::class)
        ->fillForm(['code' => 'newyear', 'discount' => '20%', 'ends_on' => '2027-01-10', 'max_uses' => 50])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreatePromoCode::class)
        ->fillForm(['code' => 'BOLE500', 'discount' => '500', 'plan_ids' => [$this->boleMonthly->id], 'once_per_member' => false])
        ->call('create')
        ->assertHasNoFormErrors();

    $newYear = PromoCode::query()->where('code', 'NEWYEAR')->sole();
    expect($newYear->available_for_all_plans)->toBeTrue()
        ->and($newYear->ends_on->toDateString())->toBe('2027-01-10')
        ->and($newYear->max_uses)->toBe(50)
        ->and($newYear->created_by_staff_id)->toBe($owner->id)
        ->and(PromoCode::query()->where('code', 'BOLE500')->sole()->limitedToPlanIds())->toBe([$this->boleMonthly->id]);
});

it('refuses a code that is taken, archived ones included, or a discount it can\'t read', function () {
    PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR'])->delete();
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(CreatePromoCode::class)
        ->fillForm(['code' => 'NewYear', 'discount' => 'half'])
        ->call('create')
        ->assertHasFormErrors(['code', 'discount']);
});

it('keeps a manager limited to a branch to codes for plans sold only there', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager, $this->bole);
    $everyPlan = PromoCode::factory()->for($this->gym)->create();

    $this->get(PromoCodeResource::getUrl('edit', ['record' => $everyPlan, 'tenant' => $this->gym]))->assertForbidden();

    Livewire::test(CreatePromoCode::class)
        ->assertFormFieldExists('plan_ids', fn ($field): bool => array_keys($field->getOptions()) === [$this->boleMonthly->id])
        ->fillForm(['code' => 'BOLE10', 'discount' => '10%', 'plan_ids' => [$this->monthly->id]])
        ->call('create')
        ->assertHasFormErrors(['plan_ids.0'])
        ->fillForm(['plan_ids' => []])
        ->call('create')
        ->assertHasFormErrors(['plan_ids' => 'required']);

    expect(PromoCode::query()->count())->toBe(1);
});

it('lists codes with where they stand and their uses', function () {
    $active = PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR', 'max_uses' => 50]);
    Membership::factory()->for(Member::factory()->for($this->main, 'homeBranch'))->create(['promo_code_id' => $active->id]);
    $upcoming = PromoCode::factory()->for($this->gym)->create(['code' => 'EASTER', 'starts_on' => '2027-04-01']);
    $ended = PromoCode::factory()->for($this->gym)->create(['code' => 'OCTOBER', 'ends_on' => '2026-10-31']);
    actingAsStaffInPanel($this->gym, StaffRole::Manager);

    Livewire::test(ListPromoCodes::class)
        ->assertCanSeeTableRecords([$active, $upcoming, $ended])
        ->assertTableColumnStateSet('status', 'Active', $active)
        ->assertTableColumnStateSet('uses', '1 of 50', $active)
        ->assertTableColumnStateSet('status', 'Upcoming', $upcoming)
        ->assertTableColumnStateSet('status', 'Ended', $ended);
});

it('archives and restores a code, and keeps the text of one already used', function () {
    $code = PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR']);
    Membership::factory()->for(Member::factory()->for($this->main, 'homeBranch'))->create(['promo_code_id' => $code->id]);
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(EditPromoCode::class, ['record' => $code->getRouteKey()])
        ->assertFormFieldDisabled('code')
        ->callAction('archive')
        ->assertNotified('Promo code archived.');

    expect($code->refresh()->trashed())->toBeTrue();

    Livewire::test(EditPromoCode::class, ['record' => $code->getRouteKey()])
        ->callAction('restore')
        ->assertNotified('Promo code restored.');

    expect($code->refresh()->trashed())->toBeFalse();
});

it('keeps promo codes from receptionists', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    $this->get(PromoCodeResource::getUrl('index', ['tenant' => $this->gym]))->assertForbidden();
});
