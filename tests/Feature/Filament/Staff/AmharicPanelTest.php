<?php

declare(strict_types=1);

use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Filament\Staff\Actions\CancelMembershipAction;
use App\Filament\Staff\Resources\CheckIns\CheckInResource;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Filament\Staff\Resources\Memberships\Pages\ListMemberships;
use App\Filament\Staff\Resources\Memberships\Pages\ViewMembership;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->branch = Branch::factory()->for($this->gym)->create();
    $hana = Member::factory()->for($this->branch, 'homeBranch')->create(['full_name' => 'Hana Girma']);
    CheckIn::factory()->for($hana)->for($this->branch)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-24 07:00', 'UTC')]);
});

afterEach(function () {
    app()->setLocale('en');
    Carbon::setLocale('en');
    CarbonImmutable::setLocale('en');
});

it('shows the panel in Amharic, with Amharic month names, to staff who chose Amharic', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    auth()->user()->update(['message_language' => 'am']);

    $this->get(CheckInResource::getUrl('index', tenant: $this->gym))
        ->assertOk()
        ->assertSee('lang="am"', escape: false)
        ->assertSee('የመግቢያ መዝገቦች')
        ->assertSee('ውጣ')
        // Filament's own Amharic says ዘግተህ ውጣ; lang/vendor overrides it.
        ->assertDontSee('ዘግተህ');

    expect(app()->getLocale())->toBe('am')
        ->and(CarbonImmutable::parse('2026-11-24')->translatedFormat('j F Y'))->toBe('24 ኖቬምበር 2026');
});

it('keeps the panel in English for everyone else', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    $this->get(MemberResource::getUrl('index', tenant: $this->gym))
        ->assertOk()
        ->assertSee('lang="en"', escape: false)
        ->assertDontSee('ውጣ');
});

it('keeps the API in English for staff who chose Amharic', function () {
    $staff = actingAsStaff($this->gym, StaffRole::Owner);
    $staff->user->update(['message_language' => 'am']);

    $this->postJson(route('api.v1.organizations.members.store', ['organization' => 'demo-gym']), ['full_name' => 'X'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.phone.0', 'The phone field is required.');
});

it('tells staff who chose Amharic why the gym refused, in Amharic', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    app()->setLocale('am'); // As UseStaffLanguage does on every panel request.
    $member = Member::factory()->for($this->branch, 'homeBranch')->create();
    $ended = Membership::factory()->for($member)->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-01-31']);

    Livewire::test(ViewMembership::class, ['record' => $ended->getRouteKey()])
        ->callAction(CancelMembershipAction::class, ['reason' => 'ሄዷል።'])
        ->assertNotified('ይህ አባልነት አስቀድሞ አብቅቷል።');
});

it('shows staff who chose Amharic the Ethiopian date with the Gregorian beside it', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    app()->setLocale('am'); // As UseStaffLanguage does on every panel request.
    $member = Member::factory()->for($this->branch, 'homeBranch')->create();
    Membership::factory()->for($member)->create(['starts_on' => '2026-11-27', 'ends_on' => '2026-12-26']);

    Livewire::test(ListMemberships::class)
        ->assertSee('ኅዳር 18, 2019 (27/11/2026)')
        ->assertSee('ታኅሣሥ 17, 2019 (26/12/2026)');
});

it('words Filament\'s own buttons and messages as the glossary sets', function () {
    app()->setLocale('am');

    expect(__('filament-actions::edit.single.label'))->toBe('አስተካክል')
        ->and(__('filament-actions::modal.actions.cancel.label'))->toBe('ተው')
        ->and(__('filament-actions::restore.single.label'))->toBe('ከማህደር መልስ')
        ->and(__('filament-actions::create.single.notifications.created.title'))->toBe('ተጨምሯል')
        ->and(trans_choice('filament::components/pagination.overview', 50, ['total' => 50, 'first' => 1, 'last' => 10]))->toBe('ከ50 ረድፎች ከ1 እስከ 10')
        // A line left out of the override keeps Filament's own.
        ->and(__('filament-tables::table.actions.enable_reordering.label'))->toBe('እንደገና መደርደር');
});
