<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Sms;

use App\Domains\Notifications\Sms\Exceptions\SmsNotSent;
use App\Domains\Shared\PhoneNumber\PhoneNumber;

/**
 * Sends a text message to a phone. SMS_DRIVER picks the driver: "log" writes
 * each message to the log, for development and tests, and "afromessage"
 * sends it through AfroMessage. See ADR 0010.
 */
interface SmsSender
{
    /**
     * @throws SmsNotSent
     */
    public function send(PhoneNumber $to, string $message): void;
}
