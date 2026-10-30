<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use App\Domains\Notifications\Sms\SendableBySms;
use App\Domains\Notifications\Sms\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells someone who already has a password that a gym added them as staff,
 * by SMS from the queue. See ADR 0019.
 */
class StaffAddedNotification extends Notification implements SendableBySms, ShouldQueue
{
    use Queueable;

    /**
     * How many times the queue tries to send it.
     */
    public int $tries = 3;

    public function __construct(public readonly string $gymName) {}

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
        $url = route('filament.staff.auth.login');

        return "{$this->gymName} added you as staff on {$appName}. Sign in at {$url} with your phone and password.";
    }
}
