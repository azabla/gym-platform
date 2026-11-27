<?php

declare(strict_types=1);

use App\Dashboards\GymFigures;
use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\Payment;
use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use App\Filament\Admin\AdminUser;
use App\Filament\Admin\Resources\Gyms\Pages\ListGyms;
use App\Filament\Admin\Resources\Gyms\Pages\ViewGym;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    // Friday 27 November 2026, 10:00 in Addis Ababa.
    $this->travelTo(CarbonImmutable::parse('2026-11-27 10:00', 'Africa/Addis_Ababa'));

    $this->demo = Organization::factory()->create(['name' => 'Demo Gym', 'slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->nairobi = Organization::factory()->create(['name' => 'Nairobi Fit', 'slug' => 'nairobi-fit', 'timezone' => 'Africa/Nairobi']);

    actingAsOrganization($this->demo);
    $main = Branch::factory()->for($this->demo)->create(['name' => 'Main Branch']);
    Branch::factory()->for($this->demo)->create(['name' => 'Bole Branch']);
    $owner = Staff::factory()->for($this->demo)->owner()->create();
    $owner->user->update(['name' => 'Meron Assefa', 'phone' => '+251911223344']);
    Staff::factory()->for($this->demo)->create();

    $hana = Member::factory()->for($main, 'homeBranch')->create(['full_name' => 'Hana Girma']);
    $archived = Member::factory()->for($main, 'homeBranch')->create(['full_name' => 'Dawit Bekele']);
    $archived->delete();

    $valid = Membership::factory()->for($hana)->create(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    Membership::factory()->for($hana)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);

    Payment::factory()->for($valid)->create(['amount' => Money::of('2,500'), 'paid_on' => '2026-11-01', 'branch_id' => $main->id]);
    Payment::factory()->for($valid)->refund()->create(['amount' => Money::of('500'), 'paid_on' => '2026-11-20', 'branch_id' => $main->id]);
    Payment::factory()->for($valid)->voided()->create(['amount' => Money::of('300'), 'paid_on' => '2026-11-21', 'branch_id' => $main->id]);
    Payment::factory()->for($valid)->create(['amount' => Money::of('1,000'), 'paid_on' => '2026-10-31', 'branch_id' => $main->id]);

    CheckIn::factory()->for($hana)->for($main)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-26 07:00', 'Africa/Addis_Ababa')->utc()]);
    CheckIn::factory()->for($hana)->for($main)->denied(CheckInDenial::OutsideHours)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-26 23:00', 'Africa/Addis_Ababa')->utc()]);
    CheckIn::factory()->for($hana)->for($main)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-15 07:00', 'Africa/Addis_Ababa')->utc()]);

    $admin = User::factory()->create(['phone' => '+251911000000']);
    $admin->makePlatformAdmin();
    $this->actingAs(AdminUser::query()->findOrFail($admin->id), 'admin_panel');
    Filament::setCurrentPanel('admin');
});

it('counts each gym\'s members, memberships, staff, check-ins and money taken, where each gym is', function () {
    $figures = app(GymFigures::class)->of([$this->demo, $this->nairobi]);

    expect($figures[$this->demo->id])->toMatchArray([
        'members' => 1,
        'valid_memberships' => 1,
        // The owner and a receptionist, plus the staff the payment and check-in factories add.
        'staff' => Staff::query()->count(),
        'check_ins_last_7_days' => 1,
    ])
        ->and($figures[$this->demo->id]['taken_this_month']->format())->toBe('2,000.00 ETB')
        ->and($figures[$this->demo->id]['last_activity'])->not->toBeNull()
        ->and($figures[$this->nairobi->id])->toMatchArray(['members' => 0, 'valid_memberships' => 0, 'staff' => 0, 'check_ins_last_7_days' => 0, 'last_activity' => null]);
});

it('lists every gym with its owner and figures, but never a member\'s name', function () {
    Livewire::test(ListGyms::class)
        ->assertCanSeeTableRecords([$this->demo, $this->nairobi])
        ->assertSee('Meron Assefa (+251911223344)')
        ->assertSee('2,000.00 ETB')
        ->assertDontSee('Hana Girma')
        ->searchTable('nairobi')
        ->assertCanSeeTableRecords([$this->nairobi])
        ->assertCanNotSeeTableRecords([$this->demo]);
});

it('shows a gym\'s figures, owners and branches, but never a member\'s name', function () {
    Livewire::test(ViewGym::class, ['record' => $this->demo->getRouteKey()])
        ->assertOk()
        ->assertSee('Meron Assefa (+251911223344)')
        ->assertSee('Bole Branch')
        ->assertSee('/staff/demo-gym')
        ->assertSee('2,000.00 ETB')
        ->assertDontSee('Hana Girma');
});
