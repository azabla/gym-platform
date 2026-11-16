<?php

declare(strict_types=1);

namespace App\Filament\Staff;

use Filament\Support\Contracts\HasLabel;

/**
 * The staff panel's menu groups, in the order they appear (ADR 0033). The
 * Dashboard and the Inbox sit above them, in no group.
 */
enum NavigationGroup: string implements HasLabel
{
    case FrontDesk = 'front_desk';
    case Money = 'money';
    case GymSetup = 'gym_setup';
    case Reports = 'reports';

    public function getLabel(): string
    {
        return match ($this) {
            self::FrontDesk => 'Front desk',
            self::Money => 'Money',
            self::GymSetup => 'Gym setup',
            self::Reports => 'Reports',
        };
    }
}
