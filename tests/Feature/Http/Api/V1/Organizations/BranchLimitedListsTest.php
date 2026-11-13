<?php

declare(strict_types=1);

use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->main = Branch::factory()->for($this->gym)->create();
    $this->bole = Branch::factory()->for($this->gym)->create();

    // Hana belongs to Main, but pays and comes in at both branches.
    $hana = Member::factory()->for($this->main, 'homeBranch')->create();
    $hanas = Membership::factory()->for($hana)->create(['price' => Money::of('5,000'), 'list_price' => Money::of('5,000')]);
    $this->atMain = Payment::factory()->for($hanas)->create(['amount' => Money::of('1,000'), 'branch_id' => $this->main->id]);
    $this->atBole = Payment::factory()->for($hanas)->create(['amount' => Money::of('2,000'), 'branch_id' => $this->bole->id]);
    $this->mainLink = PaymentLink::factory()->for($hanas)->create(['branch_id' => $this->main->id]);
    $this->boleVisit = CheckIn::factory()->for($hana)->for($this->bole)->create();
    CheckIn::factory()->for($hana)->for($this->main)->create();
});

function organizationRoute(string $name, array $parameters = []): string
{
    return route("api.v1.organizations.{$name}", ['organization' => 'demo-gym', ...$parameters]);
}

it('lists to staff limited to a branch only the payments and check-ins made there', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->bole);

    $this->getJson(organizationRoute('payments.index'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$this->atBole->id])
        ->assertJsonPath('meta.totals.net.amount_minor', 200000);

    $this->getJson(organizationRoute('check-ins.index'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$this->boleVisit->id]);

    $this->getJson(organizationRoute('payment-links.index'))->assertOk()->assertJsonCount(0, 'data');
});

it('hides a payment or link of another branch as if it weren\'t there', function () {
    actingAsStaff($this->gym, StaffRole::Accountant, $this->bole);

    $this->getJson(organizationRoute('payments.show', ['payment' => $this->atMain]))->assertNotFound();
    $this->postJson(organizationRoute('payments.void', ['payment' => $this->atMain]), ['reason' => 'Mistake.'])->assertNotFound();
    $this->getJson(organizationRoute('payment-links.show', ['payment_link' => $this->mainLink]))->assertNotFound();
    $this->postJson(organizationRoute('payment-links.cancel', ['payment_link' => $this->mainLink]))->assertNotFound();

    $this->getJson(organizationRoute('payments.show', ['payment' => $this->atBole]))->assertOk();
});

it('refuses a branch the staff member doesn\'t work at', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->bole);

    $this->getJson(organizationRoute('payments.index', ['branch_id' => $this->main->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_id']);
    $this->getJson(organizationRoute('check-ins.index', ['branch_id' => $this->main->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_id']);
});

it('shows staff at all branches the whole gym, narrowed by branch when they ask', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->getJson(organizationRoute('payments.index'))->assertJsonCount(2, 'data');
    $this->getJson(organizationRoute('check-ins.index'))->assertJsonCount(2, 'data');
    $this->getJson(organizationRoute('payment-links.index'))->assertJsonCount(1, 'data');
    $this->getJson(organizationRoute('payments.index', ['branch_id' => $this->main->id]))->assertJsonPath('data.*.id', [$this->atMain->id]);
});
