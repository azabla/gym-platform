<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

/**
 * What became of a push: sent, or not, because the phone's token no longer
 * works (the app was removed or its token replaced) and should be forgotten.
 */
enum PushResult
{
    case Sent;
    case TokenNoLongerValid;
}
