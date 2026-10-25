<?php

declare(strict_types=1);

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00', 'Africa/Addis_Ababa'));
    fakePushes();

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Demo Gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->boleBranch = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $this->hana = Member::factory()->for($this->mainBranch, 'homeBranch')->create();
    Membership::factory()->for($this->hana)->create();
});

/**
 * Where announcements are posted at the demo gym.
 */
function announcementsUrl(): string
{
    return route('api.v1.organizations.announcements.store', ['organization' => 'demo-gym']);
}

/**
 * Where the announcement is changed, or cancelled.
 */
function announcementUrl(Announcement $announcement, string $action = 'update'): string
{
    return route("api.v1.organizations.announcements.{$action}", ['organization' => 'demo-gym', 'announcement' => $announcement]);
}

it('lets a manager send an announcement now', function () {
    $manager = actingAsStaff($this->gym, StaffRole::Manager);

    $this->postJson(announcementsUrl(), [
        'title' => 'Closed on Sunday',
        'body' => 'The gym is closed this Sunday for maintenance.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.title', 'Closed on Sunday')
        ->assertJsonPath('data.branch', null)
        ->assertJsonPath('data.include_recently_expired', false)
        ->assertJsonPath('data.recipients_count', 1)
        ->assertJsonPath('data.posted_by.id', $manager->id)
        ->assertJsonPath('meta.audience_count', 1);

    expect($this->hana->notifications()->sole()->type)->toBe('announcement');
});

it('lets an owner schedule one, which goes out at its time', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->postJson(announcementsUrl(), [
        'title' => 'New classes',
        'body' => 'Strength classes start on Monday.',
        'branch_id' => $this->mainBranch->id,
        'include_recently_expired' => true,
        'send_at' => '2026-10-25T08:00:00+03:00',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.branch.id', $this->mainBranch->id)
        ->assertJsonPath('data.include_recently_expired', true)
        ->assertJsonPath('data.send_at', '2026-10-25T05:00:00+00:00')
        ->assertJsonPath('data.sent_at', null)
        ->assertJsonPath('meta.audience_count', 1);

    expect($this->hana->notifications()->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-25 08:00', 'Africa/Addis_Ababa'));
    $this->artisan('announcements:send-due')->assertSuccessful();

    expect($this->hana->notifications()->count())->toBe(1);
});

it('lets every staff member see the announcements, latest first, but only owners and managers post', function (StaffRole $role) {
    $older = Announcement::factory()->for($this->gym)->sent(12)->create(['send_at' => CarbonImmutable::now()->subDays(3)]);
    $scheduled = Announcement::factory()->for($this->gym)->create();
    Announcement::factory()->create();
    actingAsStaff($this->gym, $role);

    $this->getJson(route('api.v1.organizations.announcements.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $scheduled->id)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonPath('data.1.recipients_count', 12);

    $this->postJson(announcementsUrl(), ['title' => 'Hello', 'body' => 'Hi.'])->assertForbidden();
    $this->patchJson(announcementUrl($scheduled), ['title' => 'Hello'])->assertForbidden();
    $this->postJson(announcementUrl($scheduled, 'cancel'))->assertForbidden();
})->with([
    'receptionist' => StaffRole::Receptionist,
    'trainer' => StaffRole::Trainer,
    'accountant' => StaffRole::Accountant,
]);

it('lets a manager of some branches speak only to one of them', function () {
    actingAsStaff($this->gym, StaffRole::Manager, $this->boleBranch);
    $toTheWholeGym = Announcement::factory()->for($this->gym)->create();

    $this->postJson(announcementsUrl(), ['title' => 'Hello', 'body' => 'Hi.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('branch_id');
    $this->postJson(announcementsUrl(), ['title' => 'Hello', 'body' => 'Hi.', 'branch_id' => $this->mainBranch->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('branch_id');
    $this->postJson(announcementsUrl(), ['title' => 'Hello', 'body' => 'Hi.', 'branch_id' => $this->boleBranch->id, 'send_at' => '2026-10-25T08:00:00+03:00'])
        ->assertCreated()
        ->assertJsonPath('meta.audience_count', 0);

    $ownAnnouncement = Announcement::query()->acrossOrganizations()->latest('id')->firstOrFail();
    $this->patchJson(announcementUrl($ownAnnouncement), ['branch_id' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('branch_id');
    $this->patchJson(announcementUrl($toTheWholeGym), ['title' => 'Hello'])->assertForbidden();
});

it('checks what is posted', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->postJson(announcementsUrl(), [
        'title' => str_repeat('a', 81),
        'body' => str_repeat('a', 1001),
        'branch_id' => Branch::factory()->create()->id,
        'include_recently_expired' => 'yes',
        'send_at' => '2026-10-24T09:00:00+03:00',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'body', 'branch_id', 'include_recently_expired', 'send_at']);

    $this->postJson(announcementsUrl(), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'body']);
});

it('changes a scheduled announcement, only in the fields sent', function () {
    actingAsStaff($this->gym, StaffRole::Manager);
    $announcement = Announcement::factory()->for($this->gym)->create(['title' => 'Closed on Sunday', 'body' => 'For maintenance.']);

    $this->patchJson(announcementUrl($announcement), [
        'title' => 'Closed on Saturday',
        'branch_id' => $this->boleBranch->id,
        'send_at' => '2026-10-26T07:00:00+03:00',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.title', 'Closed on Saturday')
        ->assertJsonPath('data.body', 'For maintenance.')
        ->assertJsonPath('data.branch.id', $this->boleBranch->id)
        ->assertJsonPath('data.send_at', '2026-10-26T04:00:00+00:00');

    $this->patchJson(announcementUrl($announcement), ['branch_id' => null, 'send_at' => '2026-10-24T09:59:00+03:00'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('send_at');
    $this->patchJson(announcementUrl($announcement), ['branch_id' => null])
        ->assertOk()
        ->assertJsonPath('data.branch', null);
});

it('cancels a scheduled announcement, so it never goes', function () {
    actingAsStaff($this->gym, StaffRole::Owner);
    $announcement = Announcement::factory()->for($this->gym)->create();

    $this->postJson(announcementUrl($announcement, 'cancel'))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $this->travelTo(CarbonImmutable::now()->addDays(2));
    $this->artisan('announcements:send-due')->assertSuccessful();

    expect($this->hana->notifications()->count())->toBe(0);
    $this->postJson(announcementUrl($announcement, 'cancel'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('announcement');
    $this->patchJson(announcementUrl($announcement), ['title' => 'Hello'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('announcement');
});

it('can no longer change or cancel one that went out', function () {
    actingAsStaff($this->gym, StaffRole::Owner);
    $announcement = Announcement::factory()->for($this->gym)->sent()->create(['title' => 'Closed on Sunday']);

    $this->patchJson(announcementUrl($announcement), ['title' => 'Open on Sunday'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('announcement');
    $this->postJson(announcementUrl($announcement, 'cancel'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('announcement');

    expect($announcement->fresh()->title)->toBe('Closed on Sunday');
});

it('never shows or changes another gym\'s announcement', function () {
    actingAsStaff($this->gym, StaffRole::Owner);
    $elsewhere = Announcement::factory()->create();

    $this->patchJson(announcementUrl($elsewhere), ['title' => 'Hello'])->assertNotFound();
    $this->postJson(announcementUrl($elsewhere, 'cancel'))->assertNotFound();
});
