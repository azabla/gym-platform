<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Sms;

use App\Domains\Notifications\Sms\Exceptions\SmsNotSent;
use App\Domains\Shared\PhoneNumber\PhoneNumber;

/**
 * A notification channel that sends by SMS, through the configured SmsSender.
 * The phone comes from the notifiable's "sms" route: Notification::route('sms',
 * $phone) for anyone, or routeNotificationForSms() on a notifiable model.
 */
final class SmsChannel
{
    public function __construct(private SmsSender $smsSender) {}

    /**
     * Send the notification's text to the phone it is routed to, if any.
     *
     * @throws SmsNotSent
     */
    public function send(object $notifiable, SendableBySms $notification): void
    {
        $to = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('sms', $notification)
            : null;

        if ($to === null) {
            return;
        }

        $this->smsSender->send(
            $to instanceof PhoneNumber ? $to : PhoneNumber::of((string) $to),
            $notification->toSms($notifiable),
        );
    }
}
