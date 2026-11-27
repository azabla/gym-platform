<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Notifications\HeldWhileTheGymIsPaused;
use Illuminate\Notifications\Notification;

it('drops a message queued before the gym was paused, to its members and staff alike', function () {
    $gym = Organization::factory()->create();
    $member = Member::factory()->for(Branch::factory()->for($gym), 'homeBranch')->create();
    $staff = Staff::factory()->for($gym)->create();
    $message = new class extends Notification
    {
        use HeldWhileTheGymIsPaused;
    };

    expect($message->shouldSend($member, 'sms'))->toBeTrue()
        ->and($message->shouldSend($staff, 'push'))->toBeTrue();

    $gym->suspend(User::factory()->create(), 'Hasn\'t paid.');

    expect($message->shouldSend($member, 'sms'))->toBeFalse()
        ->and($message->shouldSend($staff, 'push'))->toBeFalse()
        ->and($message->shouldSend(User::factory()->create(), 'sms'))->toBeTrue();
});
