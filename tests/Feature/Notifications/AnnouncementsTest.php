<?php

declare(strict_types=1);

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Notifications\Models\PushToken;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Jobs\SendAnnouncement;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00', 'Africa/Addis_Ababa'));
    $this->pushes = fakePushes();

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Demo Gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole']);
});

/**
 * A member at the branch whose membership runs over the given dates, signed
 * in to the members app on one phone.
 */
function memberWithMembership(Branch $homeBranch, string $startsOn, string $endsOn): Member
{
    $member = Member::factory()->for($homeBranch, 'homeBranch')->create();
    Membership::factory()->for($member)->create(['starts_on' => $startsOn, 'ends_on' => $endsOn]);

    $phone = User::factory()->withPhone($member->phone)->create()->createToken('phone');
    PushToken::register($phone->accessToken->tokenable_id, $phone->accessToken->id, "fcm-{$member->id}", null);

    return $member;
}

/**
 * Post an announcement at the gym, as one of its owners.
 *
 * @param  array<string, mixed>  $attributes
 */
function announcementAt(Organization $gym, array $attributes = []): Announcement
{
    return Announcement::factory()->for($gym)->create([
        'title' => 'Closed on Sunday',
        'body' => 'The gym is closed this Sunday for maintenance.',
        'send_at' => CarbonImmutable::now(),
        ...$attributes,
    ]);
}

it('tells members with a membership valid today, in the inbox and by push', function () {
    $active = memberWithMembership($this->mainBranch, '2026-10-01', '2026-10-31');
    $atBole = memberWithMembership($this->bole, '2026-10-24', '2026-11-23');
    $expired = memberWithMembership($this->mainBranch, '2026-09-01', '2026-10-23');
    $upcoming = memberWithMembership($this->mainBranch, '2026-10-25', '2026-11-24');
    $announcement = announcementAt($this->gym);

    SendAnnouncement::dispatch($announcement);

    $message = $active->notifications()->sole();
    expect($message->type)->toBe('announcement')
        ->and($message->data['details'])->toEqual(['title' => 'Closed on Sunday', 'body' => 'The gym is closed this Sunday for maintenance.', 'gym' => 'Demo Gym'])
        ->and($message->data['data'])->toBe(['announcement_id' => (string) $announcement->id])
        ->and($atBole->notifications()->count())->toBe(1)
        ->and($expired->notifications()->count())->toBe(0)
        ->and($upcoming->notifications()->count())->toBe(0)
        ->and(collect($this->pushes->sent)->map(fn ($push) => $push[0])->sort()->values()->all())->toBe(["fcm-{$active->id}", "fcm-{$atBole->id}"])
        ->and($this->pushes->sent[0][1]->title)->toBe('Closed on Sunday')
        ->and($this->pushes->sent[0][1]->body)->toBe('The gym is closed this Sunday for maintenance.')
        ->and($this->pushes->sent[0][1]->data['type'])->toBe('announcement')
        ->and($announcement->fresh())
        ->sent_at->not->toBeNull()
        ->recipients_count->toBe(2);
});

it('also tells members whose membership ended in the last 30 days, when asked to', function () {
    $endedYesterday = memberWithMembership($this->mainBranch, '2026-09-23', '2026-10-23');
    $ended30DaysAgo = memberWithMembership($this->mainBranch, '2026-08-25', '2026-09-24');
    $ended31DaysAgo = memberWithMembership($this->mainBranch, '2026-08-24', '2026-09-23');

    SendAnnouncement::dispatch(announcementAt($this->gym, ['include_recently_expired' => true]));

    expect($endedYesterday->notifications()->count())->toBe(1)
        ->and($ended30DaysAgo->notifications()->count())->toBe(1)
        ->and($ended31DaysAgo->notifications()->count())->toBe(0);
});

it('tells only the members of its branch', function () {
    $atMain = memberWithMembership($this->mainBranch, '2026-10-01', '2026-10-31');
    $atBole = memberWithMembership($this->bole, '2026-10-01', '2026-10-31');

    SendAnnouncement::dispatch(announcementAt($this->gym, ['branch_id' => $this->bole->id]));

    expect($atMain->notifications()->count())->toBe(0)
        ->and($atBole->notifications()->count())->toBe(1);
});

it('never tells archived members, members of another gym, or members of a cancelled membership', function () {
    $archived = memberWithMembership($this->mainBranch, '2026-10-01', '2026-10-31');
    $archived->delete();
    $elsewhere = memberWithMembership(Branch::factory()->create(), '2026-10-01', '2026-10-31');
    $cancelled = Member::factory()->for($this->mainBranch, 'homeBranch')->create();
    Membership::factory()->for($cancelled)->cancelled()->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    $announcement = announcementAt($this->gym);

    SendAnnouncement::dispatch($announcement);

    expect($archived->notifications()->count())->toBe(0)
        ->and($elsewhere->notifications()->count())->toBe(0)
        ->and($cancelled->notifications()->count())->toBe(0)
        ->and($announcement->fresh()->recipients_count)->toBe(0);
});

it('puts it only in the inbox of members who turned off pushes about announcements', function () {
    $member = memberWithMembership($this->mainBranch, '2026-10-01', '2026-10-31');
    $member->choosePushes(['announcements' => false]);

    SendAnnouncement::dispatch(announcementAt($this->gym));

    expect($member->notifications()->count())->toBe(1)
        ->and($this->pushes->sent)->toBe([]);
});

it('shows the start of a long announcement in the push, and all of it in the inbox', function () {
    $member = memberWithMembership($this->mainBranch, '2026-10-01', '2026-10-31');
    $body = str_repeat('Strength classes start next week. ', 20);

    SendAnnouncement::dispatch(announcementAt($this->gym, ['body' => $body]));

    expect(mb_strlen($this->pushes->sent[0][1]->body))->toBe(153)
        ->and($member->notifications()->sole()->data['details']['body'])->toBe($body);
});

it('sends a scheduled announcement once its time comes, and only once', function () {
    $member = memberWithMembership($this->mainBranch, '2026-10-01', '2026-10-31');
    $announcement = announcementAt($this->gym, ['send_at' => CarbonImmutable::parse('2026-10-24 18:00', 'Africa/Addis_Ababa')->utc()]);

    $this->artisan('announcements:send-due')->assertSuccessful();
    expect($member->notifications()->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-24 18:00', 'Africa/Addis_Ababa'));
    $this->artisan('announcements:send-due')->expectsOutput('Queued 1 due announcement.')->assertSuccessful();
    $this->artisan('announcements:send-due')->expectsOutput('Queued 0 due announcements.')->assertSuccessful();
    SendAnnouncement::dispatch($announcement->fresh());

    expect($member->notifications()->count())->toBe(1)
        ->and($announcement->fresh()->recipients_count)->toBe(1);
});

it('never sends a cancelled announcement', function () {
    $member = memberWithMembership($this->mainBranch, '2026-10-01', '2026-10-31');
    $announcement = announcementAt($this->gym, ['send_at' => CarbonImmutable::now()->subMinute()]);
    $queuedBeforeCancelling = new SendAnnouncement($announcement);

    expect($announcement->cancel())->toBeTrue();
    $this->artisan('announcements:send-due')->expectsOutput('Queued 0 due announcements.');
    dispatch($queuedBeforeCancelling);

    expect($member->notifications()->count())->toBe(0)
        ->and($announcement->fresh()->sent_at)->toBeNull()
        ->and($announcement->cancel())->toBeFalse();
});

it('can no longer be edited or cancelled once sent', function () {
    $announcement = announcementAt($this->gym);
    SendAnnouncement::dispatch($announcement);

    expect($announcement->edit(['title' => 'Open on Sunday']))->toBeFalse()
        ->and($announcement->cancel())->toBeFalse()
        ->and($announcement->fresh())
        ->title->toBe('Closed on Sunday')
        ->cancelled_at->toBeNull();
});
