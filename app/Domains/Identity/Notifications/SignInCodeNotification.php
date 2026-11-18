<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use App\Domains\Identity\Models\SignInCode;
use App\Domains\Notifications\Sms\SendableBySms;
use App\Domains\Notifications\Sms\SmsChannel;
use App\Domains\Shared\Language\Language;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A sign-in code, sent by SMS from the queue, so signing in never waits for
 * the SMS provider. See ADR 0010.
 */
class SignInCodeNotification extends Notification implements SendableBySms, ShouldQueue
{
    use Queueable;

    /**
     * How many times the queue tries to send it.
     */
    public int $tries = 3;

    /**
     * @param  Language  $language  the app's language when the code was asked for (ADR 0034)
     */
    public function __construct(
        public readonly string $code,
        public readonly Language $language = Language::English,
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
        return (string) __('sms.sign_in_code', [
            'app' => (string) config('app.name'),
            'code' => $this->code,
            'minutes' => (string) SignInCode::MINUTES_VALID,
        ], $this->language->value);
    }
}
