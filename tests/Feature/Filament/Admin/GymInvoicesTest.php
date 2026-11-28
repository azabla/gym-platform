<?php

declare(strict_types=1);

use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Models\GymInvoice;
use App\Domains\PlatformBilling\Models\GymSubscription;
use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Domains\PlatformBilling\SubscriptionTerms;
use App\Filament\Admin\Resources\GymInvoices\Pages\ListGymInvoices;
use App\Filament\Admin\Resources\Gyms\Pages\ListGyms;
use App\Filament\Admin\Widgets\PlatformBillingOverview;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Africa/Addis_Ababa'));
    $this->admin = actingAsPlatformAdminInPanel();
    $plan = PlatformPlan::factory()->create(['name' => 'Standard']);
    $this->gym = Organization::factory()->create(['name' => 'Demo Gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->other = Organization::factory()->create(['name' => 'Bole Fitness', 'timezone' => 'Africa/Addis_Ababa']);
    GymSubscription::start($this->gym, new SubscriptionTerms($plan), firstBillingDay: CarbonImmutable::parse('2026-11-20'));
    GymSubscription::start($this->other, new SubscriptionTerms($plan), firstBillingDay: CarbonImmutable::parse('2026-12-02'));
    $this->artisan('platform-billing:run');
    $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00', 'Africa/Addis_Ababa'));
    $this->artisan('platform-billing:run');
    $this->overdue = GymInvoice::query()->acrossOrganizations()->where('organization_id', $this->gym->getKey())->sole();
    $this->due = GymInvoice::query()->acrossOrganizations()->where('organization_id', $this->other->getKey())->sole();
});

it('lists every gym\'s invoices, filtered by status and gym', function () {
    Livewire::test(ListGymInvoices::class)
        ->assertCanSeeTableRecords([$this->overdue, $this->due])
        ->assertSee(['INV-000001', 'Demo Gym', 'Overdue', 'Due'])
        ->filterTable('status', 'overdue')
        ->assertCanSeeTableRecords([$this->overdue])
        ->assertCanNotSeeTableRecords([$this->due])
        ->resetTableFilters()
        ->filterTable('gym', $this->other->getKey())
        ->assertCanSeeTableRecords([$this->due])
        ->assertCanNotSeeTableRecords([$this->overdue]);
});

it('voids an invoice with a reason', function () {
    Livewire::test(ListGymInvoices::class)
        ->callTableAction('void', $this->due, ['reason' => 'Wrong plan.'])
        ->assertNotified('Invoice voided.');

    expect($this->due->refresh()->void_reason)->toBe('Wrong plan.')
        ->and($this->due->voided_by_user_id)->toBe($this->admin->id);
});

it('records a payment from the gym\'s receipt, and shows it on the invoice', function () {
    Livewire::test(ListGymInvoices::class)
        ->mountTableAction('recordPayment', $this->overdue)
        ->assertTableActionDataSet(['amount' => '2000.00', 'paid_on' => '2026-11-28'])
        ->setTableActionData(['amount' => '1,200', 'method' => 'telebirr', 'reference' => 'TB123'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertNotified('Payment recorded.');

    Livewire::test(ListGymInvoices::class)
        ->callTableAction('recordPayment', $this->overdue, ['amount' => '800', 'method' => 'cash'])
        ->assertNotified('Payment recorded. The invoice is paid.');

    expect($this->overdue->refresh()->paid_at)->not->toBeNull();

    $this->get(route('platform-bill.invoice', $this->overdue->getKey()))
        ->assertSee(['Paid', 'Telebirr · TB123', '1,200.00 ETB', '800.00 ETB']);
});

it('says why a payment is refused', function () {
    Livewire::test(ListGymInvoices::class)
        ->callTableAction('recordPayment', $this->overdue, ['amount' => '5,000', 'method' => 'cash'])
        ->assertNotified('That is more than is owed on this invoice: 2,000.00 ETB.');

    expect($this->overdue->payments()->count())->toBe(0);
});

it('shows the platform\'s figures on the dashboard', function () {
    Livewire::test(PlatformBillingOverview::class)
        ->assertSee(['Owed by gyms', '4,000.00 ETB', 'Overdue gyms', 'Collected this month']);
});

it('shows what each gym owes and its bill in the gym list', function () {
    Livewire::test(ListGyms::class)
        ->assertSee(['2,000.00 ETB', 'Overdue', 'Due'])
        ->filterTable('bill', 'overdue')
        ->assertCanSeeTableRecords([$this->gym])
        ->assertCanNotSeeTableRecords([$this->other]);
});

it('shows the invoice to print, to platform admins only', function () {
    config(['platform.name' => 'Gym Platform PLC', 'platform.tin' => '0099887766', 'platform.pay_to' => ['CBE: 1000123456789']]);

    $this->get(route('platform-bill.invoice', $this->overdue->getKey()))
        ->assertOk()
        ->assertSee(['Invoice INV-000001', 'Gym Platform PLC', '0099887766', 'Demo Gym', '2,000.00 ETB', '260.87 ETB', 'CBE: 1000123456789']);

    auth('admin_panel')->logout();

    $this->get(route('platform-bill.invoice', $this->overdue->getKey()))->assertForbidden();
});
