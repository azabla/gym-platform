<?php

declare(strict_types=1);

namespace App\Domains\Announcements\Enums;

/**
 * Where an announcement stands: waiting for its time, gone out, or cancelled
 * before it went. See ADR 0015.
 */
enum AnnouncementStatus: string
{
    case Scheduled = 'scheduled';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
}
