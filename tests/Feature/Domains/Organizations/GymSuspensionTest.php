<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Exceptions\GymSuspensionRefused;
use App\Domains\Organizations\Models\GymSuspension;
use App\Domains\Organizations\Models\Organization;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['name' => 'Demo Gym']);
    $this->selam = User::factory()->create(['name' => 'Selam Tadesse']);
    $this->abel = User::factory()->create(['name' => 'Abel Tesfaye']);
});

it('pauses and resumes a gym, keeping who did it, when and why', function () {
    $this->gym->suspend($this->selam, '  Hasn\'t paid for November.  ');

    expect($this->gym->refresh()->isSuspended())->toBeTrue()
        ->and(Organization::query()->active()->pluck('id')->all())->not->toContain($this->gym->id);

    $this->gym->resume($this->abel, 'Paid on 2 December');

    $pause = GymSuspension::query()->acrossOrganizations()->sole();

    expect($this->gym->refresh()->isSuspended())->toBeFalse()
        ->and(Organization::query()->active()->pluck('id')->all())->toContain($this->gym->id)
        ->and($pause->reason)->toBe('Hasn\'t paid for November.')
        ->and($pause->suspended_by_user_id)->toBe($this->selam->id)
        ->and($pause->resumed_by_user_id)->toBe($this->abel->id)
        ->and($pause->resume_note)->toBe('Paid on 2 December')
        ->and($pause->resumed_at)->not->toBeNull();
});

it('keeps every pause, newest first', function () {
    $this->gym->suspend($this->selam, 'First');
    $this->gym->resume($this->abel);
    $this->travel(1)->day();
    $this->gym->suspend($this->selam, 'Second');

    expect($this->gym->suspensions()->pluck('reason')->all())->toBe(['Second', 'First']);
});

it('refuses to pause a paused gym, to resume one that isn\'t, or to pause without a reason', function () {
    expect(fn () => $this->gym->resume($this->abel))->toThrow(GymSuspensionRefused::class, 'This gym isn\'t paused.')
        ->and(fn () => $this->gym->suspend($this->selam, ' '))->toThrow(GymSuspensionRefused::class);

    $this->gym->suspend($this->selam, 'Hasn\'t paid.');

    expect(fn () => $this->gym->suspend($this->selam, 'Again'))->toThrow(GymSuspensionRefused::class, 'This gym is already paused.')
        ->and(GymSuspension::query()->acrossOrganizations()->count())->toBe(1);
});
