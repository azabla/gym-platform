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
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create();
    $this->bole = Branch::factory()->for($this->gym)->create();
    $this->monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->boleMonthly = Plan::factory()->for($this->gym)->create(['name' => 'Bole Monthly', 'price' => Money::of('2,000')]);
    $this->boleMonthly->makeAvailableOnlyAt($this->bole);
    $this->hana = Member::factory()->for($this->main, 'homeBranch')->create();
});

function promoCodesRoute(string $name, array $parameters = []): string
{
    return route("api.v1.organizations.promo-codes.{$name}", ['organization' => 'demo-gym', ...$parameters]);
}

function sellWithCode(Member $member, Plan $plan, array $details): TestResponse
{
    return test()->postJson(
        route('api.v1.organizations.members.memberships.store', ['organization' => 'demo-gym', 'member' => $member]),
        ['plan_id' => $plan->id, ...$details],
    );
}

it('lets the owner create a code, kept in capitals, for every plan', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->postJson(promoCodesRoute('store'), [
        'code' => 'newyear',
        'discount' => ' 20% ',
        'starts_on' => '2026-12-20',
        'ends_on' => '2027-01-10',
        'max_uses' => 50,
    ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'NEWYEAR')
        ->assertJsonPath('data.discount', '20%')
        ->assertJsonPath('data.once_per_member', true)
        ->assertJsonPath('data.available_for_all_plans', true)
        ->assertJsonPath('data.uses', 0);
});

it('refuses a code that is taken, unreadable, or out of order', function (array $details, string $field) {
    PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR'])->delete();
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->postJson(promoCodesRoute('store'), [...['code' => 'SUMMER', 'discount' => '10%'], ...$details])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'taken, even archived' => [['code' => 'NewYear'], 'code'],
    'spaces in it' => [['code' => 'NEW YEAR'], 'code'],
    'not a discount' => [['discount' => 'half'], 'discount'],
    'more than 100%' => [['discount' => '120%'], 'discount'],
    'ends before it starts' => [['starts_on' => '2027-01-10', 'ends_on' => '2027-01-01'], 'ends_on'],
]);

it('keeps a manager at some branches to codes for plans sold only there', function () {
    actingAsStaff($this->gym, StaffRole::Manager, $this->bole);

    $this->postJson(promoCodesRoute('store'), ['code' => 'BOLE10', 'discount' => '10%'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['plan_ids' => 'Choose plans sold only at branches you work at.']);

    $this->postJson(promoCodesRoute('store'), ['code' => 'BOLE10', 'discount' => '10%', 'plan_ids' => [$this->monthly->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['plan_ids']);

    $this->postJson(promoCodesRoute('store'), ['code' => 'BOLE10', 'discount' => '10%', 'plan_ids' => [$this->boleMonthly->id]])
        ->assertCreated()
        ->assertJsonPath('data.plans.0.name', 'Bole Monthly');
});

it('keeps codes from receptionists, trainers and accountants', function (StaffRole $role) {
    actingAsStaff($this->gym, $role);

    $this->getJson(promoCodesRoute('index'))->assertForbidden();
    $this->postJson(promoCodesRoute('store'), ['code' => 'SUMMER', 'discount' => '10%'])->assertForbidden();
})->with([StaffRole::Receptionist, StaffRole::Trainer, StaffRole::Accountant]);

it('lists codes with their uses, archived ones included, and archives and restores them', function () {
    $code = PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR']);
    Membership::factory()->for($this->hana)->create(['promo_code_id' => $code->id]);
    actingAsStaff($this->gym, StaffRole::Manager);

    $this->postJson(promoCodesRoute('archive', ['promo_code' => $code]))->assertOk()->assertJsonPath('data.archived', true);

    $this->getJson(promoCodesRoute('index'))
        ->assertOk()
        ->assertJsonPath('data.0.code', 'NEWYEAR')
        ->assertJsonPath('data.0.uses', 1)
        ->assertJsonPath('data.0.archived', true);

    $this->postJson(promoCodesRoute('restore', ['promo_code' => $code]))->assertOk()->assertJsonPath('data.archived', false);
});

it('changes a code, but not the text of one already used', function () {
    $code = PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR']);
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->patchJson(promoCodesRoute('update', ['promo_code' => $code]), ['discount' => '500', 'plan_ids' => [$this->monthly->id]])
        ->assertOk()
        ->assertJsonPath('data.discount', '500')
        ->assertJsonPath('data.available_for_all_plans', false);

    Membership::factory()->for($this->hana)->create(['promo_code_id' => $code->id]);

    $this->patchJson(promoCodesRoute('update', ['promo_code' => $code]), ['code' => 'NEWYEAR2'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code' => 'This code has been used, so its text can\'t change. Archive it and make a new one.']);
});

it('lets a receptionist sell with a code, though not with a typed discount', function () {
    PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR', 'discount' => '20%']);
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->main);

    sellWithCode($this->hana, $this->monthly, ['discount' => '500', 'discount_reason' => 'Friend'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['discount' => 'Only owners and managers can give a discount.']);

    sellWithCode($this->hana, $this->monthly, ['promo_code' => ' newyear '])
        ->assertCreated()
        ->assertJsonPath('data.list_price.amount_minor', 250000)
        ->assertJsonPath('data.price.amount_minor', 200000)
        ->assertJsonPath('data.discount.reason', 'Promo code NEWYEAR');
});

it('refuses to sell with a code that can\'t be used, saying why', function (Closure $setUp, string $message) {
    $setUp($this);
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->main);

    sellWithCode($this->hana, $this->monthly, ['promo_code' => 'NEWYEAR'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['promo_code' => $message]);
})->with([
    'unknown' => [fn () => null, 'This promo code doesn\'t exist. Check how it is spelled.'],
    'ended' => [fn ($test) => PromoCode::factory()->for($test->gym)->create(['code' => 'NEWYEAR', 'ends_on' => '2026-12-19']), 'This promo code ended on 19 December 2026.'],
    'archived' => [fn ($test) => PromoCode::factory()->for($test->gym)->create(['code' => 'NEWYEAR'])->delete(), 'This promo code doesn\'t exist. Check how it is spelled.'],
    'not for the plan' => [fn ($test) => PromoCode::factory()->for($test->gym)->create(['code' => 'NEWYEAR'])->makeAvailableOnlyFor($test->boleMonthly), 'This promo code isn\'t for the Monthly plan.'],
]);

it('takes a code or a typed discount, never both', function () {
    PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR']);
    actingAsStaff($this->gym, StaffRole::Owner);

    sellWithCode($this->hana, $this->monthly, ['promo_code' => 'NEWYEAR', 'discount' => '500', 'discount_reason' => 'Friend'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['promo_code']);
});
