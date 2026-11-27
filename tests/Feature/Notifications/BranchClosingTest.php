<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Notifications\Models\PushToken;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\BranchClosure;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Saturday 24 October 2026, where the gym is.
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00', 'Africa/Addis_Ababa'));
    $this->pushes = fakePushes();

    $this->gym = Organization::factory()->create(['name' => 'Demo Gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $this->owner = Staff::factory()->for($this->gym)->owner()->create();

    $this->hana = Member::factory()->for($this->main, 'homeBranch')->create(['phone' => '0922 34 56 78']);
    $this->abebe = Member::factory()->for($this->bole, 'homeBranch')->create(['phone' => '0911 22 33 44']);
    $phone = User::factory()->withPhone('0922 34 56 78')->create()->createToken('Hana\'s phone');
    PushToken::register($phone->accessToken->tokenable_id, $phone->accessToken->id, 'hanas-fcm-token', 'am');

    actingAsOrganization($this->gym);
});

it('tells members of the branch about a closure soon, in the inbox and by push', function () {
    BranchClosure::schedule($this->owner, [$this->main], CarbonImmutable::parse('2026-10-26'), CarbonImmutable::parse('2026-10-27'), 'Repairs');

    $message = $this->hana->notifications()->sole();

    expect($message->type)->toBe('branch_closed')
        ->and($message->data['details'])->toMatchArray(['branch' => 'Main Branch', 'reason' => 'Repairs', 'starts_on' => '2026-10-26', 'ends_on' => '2026-10-27', 'count' => 2])
        ->and($message->data['data'])->toBe(['branch_id' => (string) $this->main->id])
        ->and($this->pushes->sent[0][1]->title)->toBe('Main Branch ዝግ ይሆናል')
        ->and($this->pushes->sent[0][1]->body)->toBe('በDemo Gym የሚገኘው Main Branch ከጥቅምት 16 ቀን 2019 ዓ.ም. (ኦክቶበር 26, 2026) እስከ ጥቅምት 17 ቀን 2019 ዓ.ም. (ኦክቶበር 27, 2026) ዝግ ይሆናል፦ Repairs።')
        ->and($this->abebe->notifications()->count())->toBe(0);
});

it('tells them about other hours on a single day', function () {
    BranchClosure::schedule($this->owner, [$this->main], CarbonImmutable::parse('2026-10-26'), CarbonImmutable::parse('2026-10-26'), 'Timket', '08:00', '12:00');

    $message = $this->hana->notifications()->sole();

    expect($message->type)->toBe('branch_hours_changed')
        ->and(__('notifications.branch_hours_changed.title', ['branch' => 'Main Branch']))->toBe('Main Branch opens other hours')
        ->and(trans_choice('notifications.branch_hours_changed.body', 1, $message->data['details']))
        ->toBe('Main Branch at Demo Gym is open only 08:00–12:00 on 2026-10-26: Timket.');
});

it('says nothing yet about a closure more than 14 days away', function () {
    BranchClosure::schedule($this->owner, [$this->main], CarbonImmutable::parse('2026-11-07'), CarbonImmutable::parse('2026-11-07'), 'Repairs');
    BranchClosure::schedule($this->owner, [$this->main], CarbonImmutable::parse('2026-11-08'), CarbonImmutable::parse('2026-11-08'), 'Meeting');

    expect($this->hana->notifications()->pluck('data')->pluck('details.reason')->all())->toBe(['Repairs']);
});
