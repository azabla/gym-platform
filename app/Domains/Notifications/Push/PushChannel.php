<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

use App\Domains\Notifications\Models\PushToken;
use App\Domains\Notifications\Push\Exceptions\PushNotSent;

/**
 * A notification channel that pushes to every phone the notifiable is signed
 * in on, through the configured PushSender, each in its phone's language. The
 * phones come from the notifiable's "push" route: routeNotificationForPush()
 * on a notifiable model, giving its push tokens. A token FCM no longer knows
 * is forgotten. One phone failing doesn't stop the others.
 */
final class PushChannel
{
    public function __construct(private PushSender $pushSender) {}

    public function send(object $notifiable, SendableByPush $notification): void
    {
        $pushTokens = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('push', $notification)
            : null;

        foreach (is_iterable($pushTokens) ? $pushTokens : [] as $pushToken) {
            if (! $pushToken instanceof PushToken) {
                continue;
            }

            try {
                $result = $this->pushSender->send($pushToken->token, $notification->toPush($notifiable, $pushToken->locale ?? (string) config('app.fallback_locale')));
            } catch (PushNotSent $notSent) {
                report($notSent);

                continue;
            }

            if ($result === PushResult::TokenNoLongerValid) {
                $pushToken->delete();
            }
        }
    }
}
