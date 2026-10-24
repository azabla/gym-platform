<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Enums;

enum CheckInResult: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
}
