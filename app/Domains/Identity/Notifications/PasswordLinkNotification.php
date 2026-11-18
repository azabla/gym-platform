<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use App\Domains\Identity\Enums\PasswordLinkPurpose;
use App\Domains\Identity\Models\User;
use App\Domains\Notifications\Sms\SendableBySms;
use App\Domains\Notifications\Sms\SmsChannel;
use App\Domains\Shared\Language\Language;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A link to set or reset a staff member's password, sent by SMS from the
 * queue. It names the gym and the app, nothing personal. See ADR 0019. It is
 * written in the user's message language (ADR 0034).
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
        $key = match ($this->purpose) {
            PasswordLinkPurpose::Invite => 'sms.password_invite',
            PasswordLinkPurpose::Reset => 'sms.password_reset',
        };

        return (string) __($key, [
            'gym' => $this->gymName,
            'app' => (string) config('app.name'),
            'url' => route('staff.password.edit', ['token' => $this->token]),
        ], $notifiable instanceof User ? $notifiable->messageLanguage()->value : Language::English->value);
    }
}
