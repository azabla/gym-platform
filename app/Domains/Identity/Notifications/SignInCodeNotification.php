<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use App\Domains\Identity\Models\SignInCode;
use App\Domains\Notifications\Sms\SendableBySms;
use App\Domains\Notifications\Sms\SmsChannel;
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

    public function __construct(public readonly string $code) {}

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
        $minutes = SignInCode::MINUTES_VALID;

        return "Your {$appName} sign-in code is {$this->code}. It expires in {$minutes} minutes. Don't share it with anyone.";
    }
}
