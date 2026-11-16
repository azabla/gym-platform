<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Memberships\Models\MemberDiscount;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = actingAsOrganization(Organization::factory()->create(['slug' => 'demo-gym']));
    $this->main = Branch::factory()->for($this->gym)->create();
    $this->monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->tigist = Member::factory()->for($this->main, 'homeBranch')->create();
    $this->student = DiscountGroup::factory()->for($this->gym)->create(['name' => 'Student', 'discount' => '10%']);
});

function groupsRoute(string $name, array $parameters = []): string
{
    return route("api.v1.organizations.discount-groups.{$name}", ['organization' => 'demo-gym', ...$parameters]);
}

function sellMonthlyTo(Member $member, Plan $plan, array $details = []): TestResponse
{
    return test()->postJson(
        route('api.v1.organizations.members.memberships.store', ['organization' => 'demo-gym', 'member' => $member]),
        ['plan_id' => $plan->id, ...$details],
    );
}

it('lets a manager create, list, change, archive and restore groups', function () {
    actingAsStaff($this->gym, StaffRole::Manager);

    $id = $this->postJson(groupsRoute('store'), ['name' => ' Staff family ', 'discount' => '50%'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Staff family')
        ->assertJsonPath('data.members', 0)
        ->json('data.id');

    $this->postJson(groupsRoute('store'), ['name' => 'STUDENT', 'discount' => 'half'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'discount']);

    $this->patchJson(groupsRoute('update', ['discount_group' => $id]), ['discount' => '40%'])
        ->assertOk()
        ->assertJsonPath('data.discount', '40%');

    $this->postJson(groupsRoute('archive', ['discount_group' => $id]))->assertOk()->assertJsonPath('data.archived', true);
    $this->getJson(groupsRoute('index'))->assertOk()->assertJsonPath('data.*.name', ['Staff family', 'Student']);
    $this->postJson(groupsRoute('restore', ['discount_group' => $id]))->assertOk()->assertJsonPath('data.archived', false);
});

it('keeps groups from receptionists, accountants and trainers', function (StaffRole $role) {
    actingAsStaff($this->gym, $role);

    $this->getJson(groupsRoute('index'))->assertForbidden();
    $this->putJson(route('api.v1.organizations.members.discount-group.update', ['organization' => 'demo-gym', 'member' => $this->tigist]), [
        'discount_group_id' => $this->student->id,
    ])->assertForbidden();
})->with([StaffRole::Receptionist, StaffRole::Accountant, StaffRole::Trainer]);

it('puts a member in a group until a day, shows it, and takes them out', function () {
    actingAsStaff($this->gym, StaffRole::Owner);
    $memberGroupRoute = route('api.v1.organizations.members.discount-group.update', ['organization' => 'demo-gym', 'member' => $this->tigist]);

    $this->putJson($memberGroupRoute, ['discount_group_id' => $this->student->id, 'until' => '2026-12-19'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['until' => 'The last day can\'t be in the past.']);

    $this->putJson($memberGroupRoute, ['discount_group_id' => $this->student->id, 'until' => '2027-06-30'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Student')
        ->assertJsonPath('data.until', '2027-06-30');

    $this->getJson(route('api.v1.organizations.members.show', ['organization' => 'demo-gym', 'member' => $this->tigist]))
        ->assertOk()
        ->assertJsonPath('data.discount_group.name', 'Student')
        ->assertJsonPath('data.discount_group.discount', '10%');

    $this->deleteJson(route('api.v1.organizations.members.discount-group.destroy', ['organization' => 'demo-gym', 'member' => $this->tigist]))
        ->assertNoContent();

    $this->getJson(route('api.v1.organizations.members.show', ['organization' => 'demo-gym', 'member' => $this->tigist]))
        ->assertJsonPath('data.discount_group', null);
});

it('sells to a member in a group at the group\'s price, at the desk, for any role that sells', function () {
    MemberDiscount::assign($this->tigist, $this->student, null, Staff::factory()->for($this->gym)->create());
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->main);

    sellMonthlyTo($this->tigist, $this->monthly)
        ->assertCreated()
        ->assertJsonPath('data.price.formatted', '2,250.00 ETB')
        ->assertJsonPath('data.discount.reason', 'Discount group Student');
});

it('uses a promo code instead of the group only when it is better', function (string $codeDiscount, string $price, string $reason) {
    MemberDiscount::assign($this->tigist, $this->student, null, Staff::factory()->for($this->gym)->create());
    PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR', 'discount' => $codeDiscount]);
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->main);

    sellMonthlyTo($this->tigist, $this->monthly, ['promo_code' => 'NEWYEAR'])
        ->assertCreated()
        ->assertJsonPath('data.price.formatted', $price)
        ->assertJsonPath('data.discount.reason', $reason);
})->with([
    'better code' => ['20%', '2,000.00 ETB', 'Promo code NEWYEAR'],
    'better group' => ['5%', '2,250.00 ETB', 'Discount group Student'],
]);

it('lets a typed discount from an owner override the group', function () {
    MemberDiscount::assign($this->tigist, $this->student, null, Staff::factory()->for($this->gym)->create());
    actingAsStaff($this->gym, StaffRole::Owner);

    sellMonthlyTo($this->tigist, $this->monthly, ['discount' => '500', 'discount_reason' => 'Loyal member'])
        ->assertCreated()
        ->assertJsonPath('data.price.formatted', '2,000.00 ETB')
        ->assertJsonPath('data.discount.reason', 'Loyal member');
});
