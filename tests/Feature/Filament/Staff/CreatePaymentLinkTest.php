<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Actions\CreatePaymentLinkAction;
use App\Filament\Staff\Resources\Memberships\Pages\ViewMembership;
use App\Filament\Staff\Resources\PaymentLinks\PaymentLinkResource;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->gym = Organization::factory()->create();
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $plan = Plan::factory()->for($this->gym)->create(['price' => Money::of('2,500')]);
    $this->membership = Membership::factory()
        ->for(Member::factory()->for($this->main, 'homeBranch'))
        ->for($plan)
        ->create(['price' => Money::of('2,500'), 'list_price' => Money::of('2,500')]);
    ChapaAccount::factory()->for($this->gym)->create();
});

function openCreatePaymentLink(Membership $membership): mixed
{
    return Livewire::test(ViewMembership::class, ['record' => $membership->getRouteKey()])
        ->mountAction(CreatePaymentLinkAction::class);
}

it('creates a link for what the member still owes, at the receptionist\'s branch, and opens it to copy', function () {
    Payment::factory()->for($this->membership)->create(['amount' => Money::of('1,000')]);
    fakeChapaCheckout();
    $receptionist = actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->main);

    $page = openCreatePaymentLink($this->membership)
        ->assertSchemaStateSet(['amount' => '1500.00', 'branch_id' => $this->main->id])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $paymentLink = PaymentLink::query()->sole();
    $page->assertRedirect(PaymentLinkResource::getUrl('view', ['record' => $paymentLink]));
    expect($paymentLink->status)->toBe(PaymentLinkStatus::Pending)
        ->and($paymentLink->amount)->toEqual(Money::of('1,500'))
        ->and($paymentLink->branch_id)->toBe($this->main->id)
        ->and($paymentLink->created_by_staff_id)->toBe($receptionist->id)
        ->and($paymentLink->checkout_url)->toBe('https://checkout.chapa.co/checkout/payment/hana123');
});

it('offers only the branches the staff member works at', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->main);

    openCreatePaymentLink($this->membership)
        ->fillForm(['branch_id' => $this->bole->id])
        ->callMountedAction()
        ->assertHasActionErrors(['branch_id']);

    expect(PaymentLink::query()->exists())->toBeFalse();
});

it('refuses a link the rules don\'t allow, saying why', function (Closure $setUp, string $message) {
    $setUp($this);
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->main);

    openCreatePaymentLink($this->membership)
        ->fillForm(['amount' => '2,500'])
        ->callMountedAction()
        ->assertNotified($message);

    Http::assertNothingSent();
})->with([
    'no Chapa account' => [fn ($test) => ChapaAccount::query()->acrossOrganizations()->delete(), 'Connect the gym\'s Chapa account first.'],
    'a link already open' => [fn ($test) => PaymentLink::factory()->for($test->membership)->create(['branch_id' => $test->main->id]), 'This membership already has an open payment link. Cancel it to create a new one.'],
    'nothing due' => [fn ($test) => Payment::factory()->for($test->membership)->create(['amount' => Money::of('2,500')]), 'Nothing is due on this membership.'],
]);

it('keeps the action from trainers', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Trainer);

    Livewire::test(ViewMembership::class, ['record' => $this->membership->getRouteKey()])
        ->assertActionHidden(CreatePaymentLinkAction::class);
});
