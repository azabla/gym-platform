<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use App\Domains\Identity\Enums\PasswordLinkPurpose;
use App\Domains\Notifications\Sms\SendableBySms;
use App\Domains\Notifications\Sms\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A link to set or reset a staff member's password, sent by SMS from the
 * queue. It names the gym and the app, nothing personal. See ADR 0019.
 */
class PasswordLinkNotification extends Notification implements SendableBySms, ShouldQueue
{
    use Queueable;

    /**
     * How many times the queue tries to send it.
     */
    public int $tries = 3;

    public function __construct(
        public readonly string $token,
        public readonly PasswordLinkPurpose $purpose,
        public readonly string $gymName = '',
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [SmsChannel::class];
    }

    public function toSms(object $notifiable): string
    {
        $appName = (string) config('app.name');
        $url = route('staff.password.edit', ['token' => $this->token]);

        return match ($this->purpose) {
            PasswordLinkPurpose::Invite => "{$this->gymName} added you as staff on {$appName}. Set your password within 7 days: {$url}",
            PasswordLinkPurpose::Reset => "Reset your {$appName} staff password within 60 minutes: {$url} If you didn't ask for this, ignore it.",
        };
    }
}
