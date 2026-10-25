<?php

declare(strict_types=1);

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Notifications\Enums\NotificationTopic;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Jobs\SendAnnouncement;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00', 'Africa/Addis_Ababa'));
    fakePushes();

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Demo Gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->tigist = Member::factory()->for(Branch::factory()->for($this->gym), 'homeBranch')->create(['phone' => '0922 34 56 78']);
    Membership::factory()->for($this->tigist)->create();
    $this->announcement = Announcement::factory()->for($this->gym)->create([
        'title' => 'Closed on Sunday',
        'body' => 'The gym is closed this Sunday for maintenance.',
        'send_at' => CarbonImmutable::now(),
    ]);
    SendAnnouncement::dispatch($this->announcement);
});

it('shows the gym\'s own words in my inbox, whatever my language', function () {
    actingAsMember($this->tigist);

    $this->getJson(route('api.v1.me.gyms.notifications.index', ['organization' => 'demo-gym']), ['Accept-Language' => 'am,en;q=0.8'])
        ->assertOk()
        ->assertJsonPath('data.0.type', 'announcement')
        ->assertJsonPath('data.0.title', 'Closed on Sunday')
        ->assertJsonPath('data.0.body', 'The gym is closed this Sunday for maintenance.')
        ->assertJsonPath('data.0.data', ['announcement_id' => (string) $this->announcement->id]);
});

it('opens an announcement I received', function () {
    actingAsMember($this->tigist);

    $this->getJson(route('api.v1.me.gyms.announcements.show', ['organization' => 'demo-gym', 'announcement' => $this->announcement->id]))
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $this->announcement->id,
            'title' => 'Closed on Sunday',
            'body' => 'The gym is closed this Sunday for maintenance.',
            'sent_at' => $this->announcement->fresh()->sent_at->toIso8601String(),
        ]]);
});

it('never shows an announcement I didn\'t receive', function () {
    $scheduled = Announcement::factory()->for($this->gym)->create();
    $toAnotherBranch = Announcement::factory()->for($this->gym)->sent()->create();
    $atAnotherGym = Member::factory()->create(['phone' => '0922 34 56 78']);
    Membership::factory()->for($atAnotherGym)->create();
    $elsewhere = Announcement::factory()->for($atAnotherGym->organization)->create(['send_at' => CarbonImmutable::now()]);
    SendAnnouncement::dispatch($elsewhere);
    actingAsMember($this->tigist);

    foreach ([$scheduled, $toAnotherBranch, $elsewhere] as $announcement) {
        $this->getJson(route('api.v1.me.gyms.announcements.show', ['organization' => 'demo-gym', 'announcement' => $announcement->id]))
            ->assertNotFound();
    }
});

it('lets me turn off pushes about announcements', function () {
    actingAsMember($this->tigist);

    $this->patchJson(route('api.v1.me.gyms.notification-preferences.update', ['organization' => 'demo-gym']), ['announcements' => false])
        ->assertOk()
        ->assertJsonPath('data.announcements', false);

    expect($this->tigist->fresh()->wantsPushesAbout(NotificationTopic::Announcements))->toBeFalse();
});
