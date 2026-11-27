<?php

declare(strict_types=1);

use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Pages\OnlinePayments;
use App\Filament\Staff\Pages\Reports;
use App\Filament\Staff\Resources\CheckIns\CheckInResource;
use App\Filament\Staff\Resources\DiscountGroups\DiscountGroupResource;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Filament\Staff\Resources\Payments\PaymentResource;
use App\Filament\Staff\Resources\Staff\StaffResource;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Bole Fitness']);
    $main = Branch::factory()->for($this->gym)->create();
    $member = Member::factory()->for($main, 'homeBranch')->create();
    Payment::factory()->for(Membership::factory()->for($member))->create(['amount' => Money::of('1,000'), 'branch_id' => $main->id, 'paid_on' => '2026-11-05']);
});

it('groups the menu into front desk, money, gym setup and reports', function () {
    expect(MemberResource::getNavigationGroup())->toBe(NavigationGroup::FrontDesk)
        ->and(CheckInResource::getNavigationGroup())->toBe(NavigationGroup::FrontDesk)
        ->and(PaymentResource::getNavigationGroup())->toBe(NavigationGroup::Money)
        ->and(OnlinePayments::getNavigationGroup())->toBe(NavigationGroup::Money)
        ->and(DiscountGroupResource::getNavigationGroup())->toBe(NavigationGroup::GymSetup)
        ->and(StaffResource::getNavigationGroup())->toBe(NavigationGroup::GymSetup)
        ->and(Reports::getNavigationGroup())->toBe(NavigationGroup::Reports)
        ->and(DiscountGroupResource::getNavigationIcon())->not->toBe(StaffResource::getNavigationIcon());
});

it('shows the gym\'s name as the panel\'s brand', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    $this->get(route('filament.staff.pages.dashboard', ['tenant' => 'demo-gym']))
        ->assertOk()
        ->assertSee('Bole Fitness');
});

it('lists every report for an owner, and downloads one', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(Reports::class)
        ->assertSee(['Payments', 'Memberships sold', 'Discounts', 'Check-ins', 'Members'])
        ->callAction(TestAction::make('downloadPayments')->schemaComponent('downloadPayments', 'content'), ['from' => '2026-11-01', 'to' => '2026-11-30'])
        ->assertHasNoFormErrors()
        ->assertFileDownloaded('demo-gym-payments-2026-11-01-to-2026-11-30.csv');
});

it('shows a trainer only the check-ins report', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Trainer);

    Livewire::test(Reports::class)
        ->assertSee('Every visit in the period')
        ->assertDontSee('Every payment and refund')
        ->assertDontSee('Every membership sold below its price');
});

it('lists the insights an owner may download, and downloads one', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(Reports::class)
        ->assertSee(['Revenue by month', 'Renewals and churn', 'Busiest hours', 'Members and visit packs'])
        ->callAction(TestAction::make('downloadRenewals')->schemaComponent('insight-renewals', 'content'), ['months' => 6])
        ->assertHasNoFormErrors()
        ->assertFileDownloaded('demo-gym-renewals-last-6-months.csv');
});

it('shows an accountant only the revenue insight', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Accountant);

    Livewire::test(Reports::class)
        ->assertSee('Revenue by month')
        ->assertDontSee('Renewals and churn')
        ->assertDontSee('Busiest hours');
});
