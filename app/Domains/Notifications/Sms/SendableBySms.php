<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Sms;

/**
 * A notification that can go by SMS: its via() lists SmsChannel, and it
 * gives the text of the message.
 */
interface SendableBySms
{
    public function toSms(object $notifiable): string;
}
