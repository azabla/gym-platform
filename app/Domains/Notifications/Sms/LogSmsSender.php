<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Sms;

use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Illuminate\Support\Facades\Log;

/**
 * Writes each message to the log instead of sending it, for development and
 * tests, or until the SMS provider account is set up. Never use it in
 * production: messages such as sign-in codes would sit in the log.
 */
final class LogSmsSender implements SmsSender
{
    public function send(PhoneNumber $to, string $message): void
    {
        Log::info("SMS to {$to->e164}: {$message}");
    }
}
