<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

/**
 * What a push notification shows on the phone, and the data the app uses to
 * open the right screen when it is tapped. FCM takes only strings as data.
 * The title and body must be fine on a lock screen: no names, phone numbers
 * or payment references. See ADR 0012.
 */
final readonly class PushMessage
{
    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $data = [],
    ) {}
}
