<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

use Illuminate\Support\Facades\Log;

/**
 * Writes each push to the log instead of sending it, for development and
 * tests, or until the Firebase projects are set up.
 */
final class LogPushSender implements PushSender
{
    public function send(string $deviceToken, PushMessage $message): PushResult
    {
        Log::info('Push to device …'.substr($deviceToken, -8).": {$message->title} | {$message->body}", $message->data);

        return PushResult::Sent;
    }
}
