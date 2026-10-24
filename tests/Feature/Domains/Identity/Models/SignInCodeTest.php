<?php

declare(strict_types=1);

use App\Domains\Identity\Models\SignInCode;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('deletes codes a day after they expire, every day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00:00'));
    SignInCode::issue(PhoneNumber::of('0922 34 56 78'));
    $this->travelTo(CarbonImmutable::parse('2026-10-23 08:00:00'));
    SignInCode::issue(PhoneNumber::of('0922 34 56 78'));

    $this->artisan('model:prune', ['--model' => [SignInCode::class]])->assertSuccessful();

    expect(SignInCode::query()->sole()->created_at?->toDateString())->toBe('2026-10-23');

    $prune = collect(app(Schedule::class)->events())
        ->sole(fn (Event $event): bool => str_contains((string) $event->command, 'model:prune'));

    expect($prune->expression)->toBe('0 0 * * *')
        ->and($prune->command)->toContain('SignInCode');
});
