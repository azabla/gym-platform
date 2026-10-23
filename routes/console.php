<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Chapa's own call after a payment may not arrive, so open payment links are checked regularly (ADR 0009).
Schedule::command('billing:check-payment-links')->everyTenMinutes()->withoutOverlapping();
