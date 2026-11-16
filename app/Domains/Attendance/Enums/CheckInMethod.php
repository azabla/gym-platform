<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Enums;

/**
 * How a member checked in: by scanning their QR code, or by staff at the
 * desk, for example when they forgot their phone.
 */
enum CheckInMethod: string
{
    case Qr = 'qr';
    case Desk = 'desk';

    /**
     * The method in words, as the staff panel and reports show it.
     */
    public function label(): string
    {
        return $this === self::Qr ? 'QR code' : 'At the desk';
    }
}
