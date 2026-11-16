<?php

declare(strict_types=1);

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Filament\Staff\Resources\Announcements\AnnouncementResource;
use App\Filament\Staff\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Staff\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Staff\Resources\Announcements\Pages\ListAnnouncements;
use App\Jobs\SendAnnouncement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', 'Africa/Addis_Ababa'));
    Queue::fake();

    $this->gym = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $hana = Member::factory()->for($this->main, 'homeBranch')->create();
    Membership::factory()->for($hana)->create(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
});

it('sends an announcement now, saying how many members it reaches', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(CreateAnnouncement::class)
        ->fillForm(['title' => 'Closed on Sunday', 'body' => 'The gym is closed this Sunday.'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('It goes to 1 member.');

    $announcement = Announcement::query()->sole();

    expect($announcement->branch_id)->toBeNull();
    Queue::assertPushed(SendAnnouncement::class);
});

it('schedules one at a time in the gym\'s time zone, kept in UTC', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager);

    Livewire::test(CreateAnnouncement::class)
        ->fillForm([
            'title' => 'New classes',
            'body' => 'Spinning starts on Monday.',
            'branch_id' => $this->main->id,
            'send_now' => false,
            'send_at' => '2026-11-14 07:00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Announcement::query()->sole()->send_at->utc()->toDateTimeString())->toBe('2026-11-14 04:00:00');
    Queue::assertNotPushed(SendAnnouncement::class);
});

it('refuses a time in the past', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(CreateAnnouncement::class)
        ->fillForm(['title' => 'Late', 'body' => 'Too late.', 'send_now' => false, 'send_at' => '2026-11-13 08:00'])
        ->call('create')
        ->assertHasFormErrors(['send_at']);

    expect(Announcement::query()->count())->toBe(0);
});

it('makes a manager limited to a branch speak to one of theirs', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager, $this->bole);

    Livewire::test(CreateAnnouncement::class)
        ->fillForm(['title' => 'Hello', 'body' => 'Hello, Bole.'])
        ->call('create')
        ->assertHasFormErrors(['branch_id']);

    Livewire::test(CreateAnnouncement::class)
        ->fillForm(['title' => 'Hello', 'body' => 'Hello, Bole.', 'branch_id' => $this->bole->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Announcement::query()->sole()->branch_id)->toBe($this->bole->id);
});

it('changes or cancels one that hasn\'t gone out, but not one that has', function () {
    $owner = actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $scheduled = Announcement::post($owner, 'Old title', 'Body.', null, false, CarbonImmutable::parse('2026-11-20 07:00', 'Africa/Addis_Ababa'));
    $sent = Announcement::post($owner, 'Sent', 'Body.');
    $sent->claimForSending();

    Livewire::test(EditAnnouncement::class, ['record' => $scheduled->getRouteKey()])
        ->fillForm(['title' => 'New title'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($scheduled->refresh()->title)->toBe('New title')
        ->and($scheduled->send_at->utc()->toDateTimeString())->toBe('2026-11-20 04:00:00');

    Livewire::test(ListAnnouncements::class)
        ->callTableAction('cancel', $scheduled)
        ->assertTableActionHidden('cancel', $sent);

    expect($scheduled->refresh()->cancelled_at)->not->toBeNull();

    $this->get(AnnouncementResource::getUrl('edit', ['record' => $sent, 'tenant' => $this->gym]))->assertForbidden();
});

it('shows announcements to receptionists, but lets them post none', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    Livewire::test(ListAnnouncements::class)->assertOk();

    $this->get(AnnouncementResource::getUrl('create', ['tenant' => $this->gym]))->assertForbidden();
});
