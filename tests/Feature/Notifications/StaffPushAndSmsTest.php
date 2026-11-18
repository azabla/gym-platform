<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Notifications\Enums\PushApp;
use App\Domains\Notifications\Models\PushToken;
use App\Domains\Notifications\Sms\SmsSender;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use App\Notifications\MembersToCall;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->pushes = fakePushes();
    $this->texts = new class implements SmsSender
    {
        /** @var list<array{string, string}> */
        public array $sent = [];

        public function send(PhoneNumber $to, string $message): void
        {
            $this->sent[] = [$to->e164, $message];
        }
    };
    app()->instance(SmsSender::class, $this->texts);

    $this->gym = Organization::factory()->create(['name' => 'Demo Gym', 'slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->user = User::factory()->withPhone('0911 22 33 44')->create();
    $this->staff = Staff::factory()->for($this->gym)->for($this->user)->create(['role' => StaffRole::Manager]);

    PushToken::register($this->user->id, $this->user->createToken('staff app')->accessToken->id, 'staff-app-token', 'en', PushApp::Staff);
    PushToken::register($this->user->id, $this->user->createToken('members app')->accessToken->id, 'members-app-token', 'en');
});

/**
 * Tell the staff member about members to call, at the given time where the gym is.
 */
function tellAboutMembersToCallAt(Staff $staff, string $localTime): void
{
    test()->travelTo(CarbonImmutable::parse($localTime, 'Africa/Addis_Ababa'));
    $staff->notify(MembersToCall::count(3));
}

it('pushes staff messages to the staff app, and texts none until asked', function () {
    tellAboutMembersToCallAt($this->staff, '2026-11-19 08:00');

    expect($this->pushes->sent)->toHaveCount(1)
        ->and($this->pushes->sent[0][0])->toBe('staff-app-token')
        ->and($this->pushes->sent[0][1]->title)->toBe('Members to call')
        ->and($this->pushes->sent[0][1]->data)->toMatchArray(['type' => 'staff_members_to_call', 'gym' => 'demo-gym'])
        ->and($this->texts->sent)->toBe([])
        ->and($this->staff->notifications()->count())->toBe(1);
});

it('texts a topic the staff member turned on, in the daytime only', function () {
    $this->staff->chooseNotifications(['members_to_call' => ['sms' => true, 'push' => false]]);

    tellAboutMembersToCallAt($this->staff, '2026-11-19 08:00');
    tellAboutMembersToCallAt($this->staff, '2026-11-19 22:30');
    tellAboutMembersToCallAt($this->staff, '2026-11-20 06:59');

    expect($this->texts->sent)->toBe([
        ['+251911223344', 'Demo Gym: 3 members to call today, memberships ending within 7 days.'],
    ])
        ->and($this->pushes->sent)->toBe([])
        ->and($this->staff->notifications()->count())->toBe(3);
});

it('writes the push and the text in the staff member\'s message language', function () {
    $this->user->update(['message_language' => 'am']);
    $this->staff->chooseNotifications(['members_to_call' => ['sms' => true]]);

    tellAboutMembersToCallAt($this->staff->refresh(), '2026-11-19 08:00');

    expect($this->pushes->sent[0][1]->title)->toBe('የሚደወልላቸው አባላት')
        ->and($this->texts->sent[0][1])->toBe('Demo Gym፦ ዛሬ የሚደወልላቸው 3 አባላት አሉ፤ አባልነታቸው በ7 ቀናት ውስጥ ያበቃል።');
});
