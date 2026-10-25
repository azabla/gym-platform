<?php

use App\Domains\Identity\Models\SignInCode;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Chapa's own call after a payment may not arrive, so open payment links are checked regularly (ADR 0009).
Schedule::command('billing:check-payment-links')->everyTenMinutes()->withoutOverlapping();

// Sign-in codes are deleted a day after they expire (ADR 0010).
Schedule::command('model:prune', ['--model' => [SignInCode::class]])->daily();

// Members hear before their membership ends, and the day after; each gym's go out from 09:00 its time (ADR 0012).
Schedule::command('notifications:send-membership-reminders')->hourly()->withoutOverlapping();
