<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

use App\Domains\Notifications\Push\Exceptions\PushNotSent;

/**
 * Sends a push notification to one phone, by its push token. PUSH_DRIVER picks
 * the driver: "log" writes each push to the log, for development and tests,
 * and "fcm" sends it through Firebase Cloud Messaging. See ADR 0012.
 */
interface PushSender
{
    /**
     * @throws PushNotSent
     */
    public function send(string $deviceToken, PushMessage $message): PushResult;
}
