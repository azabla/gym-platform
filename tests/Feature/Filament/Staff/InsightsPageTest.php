<?php

declare(strict_types=1);

use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Pages\Dashboard;
use App\Filament\Staff\Pages\Insights;
use App\Filament\Staff\Widgets\Insights\BusyHoursGrid;
use App\Filament\Staff\Widgets\Insights\MembersTable;
use App\Filament\Staff\Widgets\Insights\RenewalsChart;
use App\Filament\Staff\Widgets\Insights\RenewalsTable;
use App\Filament\Staff\Widgets\Insights\RevenueChart;
use App\Filament\Staff\Widgets\Insights\RevenueTable;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    // Tuesday 24 November 2026, where the gym is.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create();
    $this->bole = Branch::factory()->for($this->gym)->create();
    $hana = Member::factory()->for($this->main, 'homeBranch')->create();
    $membership = Membership::factory()->for($hana)->for(Plan::factory()->for($this->gym)->create(['name' => 'Monthly']))->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    Payment::factory()->for($membership)->create(['amount' => Money::of('2,500'), 'paid_on' => '2026-10-01', 'branch_id' => $this->main->id]);
    Payment::factory()->for($membership)->create(['amount' => Money::of('600'), 'paid_on' => '2026-10-02', 'branch_id' => $this->bole->id]);
    CheckIn::factory()->for($hana)->for($this->main)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-23 06:30', 'Africa/Addis_Ababa')->utc()]);
});

it('shows an owner every insight, with figures beneath each chart', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    $this->get(Insights::getUrl(tenant: $this->gym))->assertOk()->assertSee('Insights');

    Livewire::test(RevenueTable::class, ['pageFilters' => ['months' => 3]])
        ->assertSee('Oct 2026')
        ->assertSee('3,100.00 ETB');
    Livewire::test(RenewalsTable::class)->assertSee('Oct 2026')->assertSee('Lapsed most');
    Livewire::test(BusyHoursGrid::class)->assertSee('Monday')->assertSee('06');
    Livewire::test(MembersTable::class)->assertSee('Active at month end');
    Livewire::test(RevenueChart::class)->assertOk();
    Livewire::test(RenewalsChart::class)->assertOk();
});

it('shows the insights in Amharic, with Amharic months and weekdays, to staff who chose Amharic', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    // As UseStaffLanguage does on every panel request.
    app()->setLocale('am');
    CarbonImmutable::setLocale('am');

    Livewire::test(RevenueTable::class, ['pageFilters' => ['months' => 3]])->assertSee('ኦክቶበር 2026')->assertSee('የተጣራ');
    Livewire::test(BusyHoursGrid::class)->assertSee('ሰኞ');
    Livewire::test(RevenueChart::class)->assertOk();

    app()->setLocale('en');
    CarbonImmutable::setLocale('en');
});

it('follows the branch chosen, within the staff member\'s branches', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(RevenueTable::class, ['pageFilters' => ['months' => 3, 'branch_id' => $this->bole->id]])
        ->assertSee('600.00 ETB')
        ->assertDontSee('3,100.00 ETB');
});

it('shows each role only the insights it is for, and keeps them off the dashboard', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Accountant);

    expect(RevenueTable::canView())->toBeTrue()
        ->and(RenewalsTable::canView())->toBeFalse()
        ->and(Insights::canAccess())->toBeTrue()
        ->and(collect(app(Dashboard::class)->getWidgets())->contains(RevenueChart::class))->toBeFalse();

    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    expect(Insights::canAccess())->toBeFalse();
    $this->get(Insights::getUrl(tenant: $this->gym))->assertForbidden();
});

it('downloads an insight from its table', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager);

    Livewire::test(RenewalsTable::class)
        ->callTableAction('downloadRenewals', data: ['months' => 3])
        ->assertFileDownloaded('demo-gym-renewals-last-3-months.csv');
});
