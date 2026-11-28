<?php

use App\Domains\Identity\Models\PasswordLink;
use App\Domains\Identity\Models\SignInCode;
use App\Domains\Imports\Models\MemberImportRow;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Chapa's own call after a payment may not arrive, so open payment links are checked regularly (ADR 0009).
Schedule::command('billing:check-payment-links')->everyTenMinutes()->withoutOverlapping();

// Sign-in codes and password links are deleted a day after they expire (ADRs 0010 and 0019),
// and the rows of member imports, with their names and phones, 30 days after they finish (ADR 0044).
Schedule::command('model:prune', ['--model' => [SignInCode::class, PasswordLink::class, MemberImportRow::class]])->daily();

// Members hear before their membership ends, and the day after; each gym's go out from 09:00 its time (ADR 0012).
Schedule::command('notifications:send-membership-reminders')->hourly()->withoutOverlapping();

// Staff hear how many members to call, once a day from 08:00 each gym's time (ADR 0027).
Schedule::command('notifications:send-staff-morning-list')->hourly()->withoutOverlapping();

// Members hear of a closure the day before, from 09:00 where each gym is (ADR 0039).
Schedule::command('notifications:send-closure-reminders')->hourly()->withoutOverlapping();

// Yearly holidays come back on the same Ethiopian date once they end (ADR 0039).
Schedule::command('branch-closures:repeat-yearly')->hourly()->withoutOverlapping();

// The platform's invoices to gyms are issued a week before each period, paused gyms included (ADR 0045).
Schedule::command('platform-billing:run')->hourly()->withoutOverlapping();

// Scheduled announcements go out within a minute of their time (ADR 0015).
Schedule::command('announcements:send-due')->everyMinute()->withoutOverlapping();

// The database is dumped to the private backups bucket at 02:00 in Addis Ababa, on the deployed app only (ADR 0028).
Schedule::command('db:backup')->dailyAt('23:00')->environments('production')->withoutOverlapping();
