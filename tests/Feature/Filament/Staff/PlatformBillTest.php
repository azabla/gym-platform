<?php

declare(strict_types=1);

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\PlatformBilling\Models\GymInvoice;
use App\Domains\PlatformBilling\Models\GymSubscription;
use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Domains\PlatformBilling\SubscriptionTerms;
use App\Filament\Staff\Auth\Login;
use App\Filament\Staff\Pages\PlatformBill;
use App\Filament\Staff\PanelUser;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    config(['platform.pay_to' => ['CBE: 1000123456789 (Gym Platform PLC)', 'Telebirr: 0911000000']]);
    $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00', 'Africa/Addis_Ababa'));
    $this->gym = Organization::factory()->create(['name' => 'Demo Gym', 'slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    GymSubscription::start($this->gym, new SubscriptionTerms(PlatformPlan::factory()->create(['name' => 'Standard'])), firstBillingDay: CarbonImmutable::parse('2026-12-01'));
    $this->artisan('platform-billing:run');
    $this->invoice = GymInvoice::query()->acrossOrganizations()->sole();
});

function signInToThePanelAs(Organization $gym, StaffRole $role): Staff
{
    $staff = Staff::factory()->for($gym)->create(['role' => $role]);
    test()->actingAs(PanelUser::query()->findOrFail($staff->user_id), 'staff_panel');

    return $staff;
}

it('shows owners their plan, invoices and how to pay', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(PlatformBill::class)
        ->assertSee(['Standard, 2,000.00 ETB a month', 'From 1 January 2027', 'CBE: 1000123456789 (Gym Platform PLC)', 'INV-000001'])
        ->assertCanSeeTableRecords([$this->invoice])
        ->filterTable('status', 'paid')
        ->assertCanNotSeeTableRecords([$this->invoice]);
});

it('is for owners only, and so is the banner', function (StaffRole $role) {
    signInToThePanelAs($this->gym, $role);

    $this->get(PlatformBill::getUrl(tenant: $this->gym))->assertForbidden();
    $this->get(route('filament.staff.pages.dashboard', ['tenant' => 'demo-gym']))->assertOk()->assertDontSee('INV-000001');
})->with([
    'manager' => [StaffRole::Manager],
    'accountant' => [StaffRole::Accountant],
    'receptionist' => [StaffRole::Receptionist],
]);

it('shows owners a banner while an invoice is due, and once overdue the day it pauses the gym', function () {
    signInToThePanelAs($this->gym, StaffRole::Owner);

    $this->get(route('filament.staff.pages.dashboard', ['tenant' => 'demo-gym']))
        ->assertOk()
        ->assertSee('Platform invoice INV-000001 of 2,000.00 ETB is due on 1 Dec 2026.');

    $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00', 'Africa/Addis_Ababa'));

    $this->get(route('filament.staff.pages.dashboard', ['tenant' => 'demo-gym']))
        ->assertSee('Platform invoice INV-000001 of 2,000.00 ETB was due on 1 Dec 2026. Unless it is paid, the gym is paused on 15 Dec 2026.');
});

it('lets an owner of a gym paused for its bill sign in, and shows them what to pay; other staff only the message', function () {
    $owner = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Owner]);
    $owner->user->update(['phone' => '+251911223344', 'password' => 'secret-password']);
    $receptionist = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Receptionist]);
    $receptionist->user->update(['phone' => '+251911556677', 'password' => 'secret-password']);
    $this->travelTo(CarbonImmutable::parse('2026-12-15 10:00', 'Africa/Addis_Ababa'));
    $this->artisan('platform-billing:run');
    Filament::setCurrentPanel('staff');

    Livewire::test(Login::class)
        ->fillForm(['phone' => '0911556677', 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['phone']);

    Livewire::test(Login::class)
        ->fillForm(['phone' => '0911223344', 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->actingAs(PanelUser::query()->findOrFail($owner->user_id), 'staff_panel');

    $this->get(route('filament.staff.pages.dashboard', ['tenant' => 'demo-gym']))
        ->assertForbidden()
        ->assertSee(['To reopen the gym, pay', 'INV-000001', '2,000.00 ETB', 'Telebirr: 0911000000', 'the gym reopens by itself']);
});

it('shows other staff of a gym paused for its bill only that it is paused', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-15 10:00', 'Africa/Addis_Ababa'));
    $this->artisan('platform-billing:run');
    signInToThePanelAs($this->gym, StaffRole::Manager);

    $this->get(route('filament.staff.pages.dashboard', ['tenant' => 'demo-gym']))
        ->assertForbidden()
        ->assertSee('paused')
        ->assertDontSee('INV-000001');
});

it('shows owners the invoice to print in their language, and nobody at another gym', function () {
    $owner = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Owner]);
    $owner->user->update(['message_language' => 'am']);
    $this->actingAs(PanelUser::query()->findOrFail($owner->user_id), 'staff_panel');

    $this->get(route('platform-bill.invoice', $this->invoice->getKey()))
        ->assertOk()
        ->assertSee(['የክፍያ መጠየቂያ INV-000001', 'ከዚህ ውስጥ ተ.እ.ታ (15%)']);

    signInToThePanelAs(Organization::factory()->create(), StaffRole::Owner);

    $this->get(route('platform-bill.invoice', $this->invoice->getKey()))->assertForbidden();
});
