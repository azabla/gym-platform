<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Enums;

/**
 * What a message to a member is about. A member can turn off pushes for each
 * topic at each gym; the inbox gets every message either way. See ADR 0012
 * and, for announcements, ADR 0015.
 */
enum NotificationTopic: string
{
    case Membership = 'membership';
    case Payments = 'payments';
    case Visits = 'visits';
    case Announcements = 'announcements';
}
