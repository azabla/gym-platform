<?php

declare(strict_types=1);

use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Actions\DownloadReportAction;
use App\Filament\Staff\Resources\CheckIns\Pages\ListCheckIns;
use App\Filament\Staff\Resources\Members\Pages\ListMembers;
use App\Filament\Staff\Resources\Memberships\Pages\ListMemberships;
use App\Filament\Staff\Resources\Payments\Pages\ListPayments;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->main = Branch::factory()->for($this->gym)->create();
    $member = Member::factory()->for($this->main, 'homeBranch')->create();
    Payment::factory()->for(Membership::factory()->for($member))->create(['amount' => Money::of('1,000'), 'branch_id' => $this->main->id, 'paid_on' => '2026-11-05']);
});

it('downloads the payments of a period from the payments list', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Accountant);

    Livewire::test(ListPayments::class)
        ->callAction(DownloadReportAction::class, ['from' => '2026-11-01', 'to' => '2026-11-30'])
        ->assertHasNoFormErrors()
        ->assertFileDownloaded('demo-gym-payments-2026-11-01-to-2026-11-30.csv');
});

it('refuses a period that ends before it starts or lasts more than a year', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(ListPayments::class)
        ->callAction(DownloadReportAction::class, ['from' => '2026-11-10', 'to' => '2026-11-01'])
        ->assertHasFormErrors(['to']);

    Livewire::test(ListPayments::class)
        ->callAction(DownloadReportAction::class, ['from' => '2025-11-01', 'to' => '2026-11-13'])
        ->assertHasFormErrors(['to']);
});

it('downloads the members as they are today, without a period', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager);

    Livewire::test(ListMembers::class)
        ->callAction(DownloadReportAction::class)
        ->assertFileDownloaded('demo-gym-members-2026-11-13.csv');
});

it('shows each download only to the staff the report is for', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    Livewire::test(ListPayments::class)->assertActionHidden(DownloadReportAction::class);
    Livewire::test(ListMemberships::class)->assertActionHidden(DownloadReportAction::class);
    Livewire::test(ListMembers::class)->assertActionHidden(DownloadReportAction::class);
    Livewire::test(ListCheckIns::class)
        ->assertActionVisible(DownloadReportAction::class)
        ->callAction(DownloadReportAction::class)
        ->assertFileDownloaded('demo-gym-check-ins-2026-11-13.csv');
});
