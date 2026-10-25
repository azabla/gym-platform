<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Notifications\Models\PushToken;
use App\Domains\Notifications\Sms\SmsSender;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Demo Gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create();
    $this->tigist = Member::factory()->for($this->mainBranch, 'homeBranch')->create(['phone' => '0922 34 56 78']);
    $this->monthly = Plan::factory()->for($this->gym)->create([
        'name' => 'Monthly',
        'price' => Money::of('2,500'),
        'duration_count' => 1,
        'duration_unit' => DurationUnit::Month,
    ]);
    $this->membership = Membership::factory()->for($this->tigist)->for($this->monthly)->create([
        'starts_on' => '2026-09-28',
        'ends_on' => '2026-10-27',
    ]);

    $tigistsPhone = User::factory()->withPhone('0922 34 56 78')->create()->createToken('Tigist\'s phone');
    PushToken::register($tigistsPhone->accessToken->tokenable_id, $tigistsPhone->accessToken->id, 'tigists-fcm-token', null);
});

/**
 * Run the reminders at the given time where the gym is.
 */
function remindAt(string $localTime): void
{
    test()->travelTo(CarbonImmutable::parse($localTime, 'Africa/Addis_Ababa'));
    test()->artisan('notifications:send-membership-reminders')->assertSuccessful();
}

it('reminds the member 5, 3 and 1 days before the membership ends, by SMS only at 3', function () {
    remindAt('2026-10-22 09:00');
    remindAt('2026-10-24 09:00');
    remindAt('2026-10-26 09:00');

    expect($this->tigist->notifications()->pluck('type')->all())->toBe(['membership_expiring', 'membership_expiring', 'membership_expiring'])
        ->and(array_map(fn ($push) => $push[1]->body, $this->pushes->sent))->toBe([
            'Your Monthly membership at Demo Gym ends in 5 days, on 2026-10-27.',
            'Your Monthly membership at Demo Gym ends in 3 days, on 2026-10-27.',
            'Your Monthly membership at Demo Gym ends tomorrow, 2026-10-27.',
        ])
        ->and($this->texts->sent)->toBe([
            ['+251922345678', 'Demo Gym: your Monthly membership ends on 2026-10-27. Renew at the desk to keep training.'],
        ]);
});

it('tells the member the day after it ended, by SMS too', function () {
    remindAt('2026-10-28 10:00');

    expect($this->tigist->notifications()->sole()->type)->toBe('membership_expired')
        ->and($this->pushes->sent[0][1]->title)->toBe('Membership ended')
        ->and($this->texts->sent)->toBe([
            ['+251922345678', 'Demo Gym: your Monthly membership ended on 2026-10-27. Renew at the desk to keep training.'],
        ]);
});

it('sends each reminder once, however often it runs', function () {
    remindAt('2026-10-24 09:00');
    remindAt('2026-10-24 10:00');
    remindAt('2026-10-24 23:00');

    expect($this->tigist->notifications()->count())->toBe(1)
        ->and($this->texts->sent)->toHaveCount(1);
});

it('waits until 09:00 where the gym is', function () {
    remindAt('2026-10-24 08:59');
    expect($this->tigist->notifications()->count())->toBe(0);

    remindAt('2026-10-24 09:00');
    expect($this->tigist->notifications()->count())->toBe(1);
});

it('says nothing when the next membership is already sold, or the member left the gym', function () {
    Membership::factory()->for($this->tigist)->for($this->monthly)->create(['starts_on' => '2026-10-28', 'ends_on' => '2026-11-27']);
    $abebe = Member::factory()->for($this->mainBranch, 'homeBranch')->create();
    Membership::factory()->for($abebe)->for($this->monthly)->create(['starts_on' => '2026-09-28', 'ends_on' => '2026-10-27']);
    $abebe->delete();

    remindAt('2026-10-24 09:00');
    remindAt('2026-10-28 09:00');

    expect(DB::table('notifications')->count())->toBe(0)
        ->and($this->texts->sent)->toBe([]);
});
