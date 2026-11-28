<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * The expense categories every gym starts with (ADR 0047). Until a gym
 * renames one, its name is this label, in each reader's language.
 */
enum DefaultExpenseCategory: string
{
    case Rent = 'rent';
    case Salaries = 'salaries';
    case Utilities = 'utilities';
    case Equipment = 'equipment';
    case Maintenance = 'maintenance';
    case Cleaning = 'cleaning';
    case Marketing = 'marketing';
    case Taxes = 'taxes';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Rent => 'Rent',
            self::Salaries => 'Salaries',
            self::Utilities => 'Electricity, water and internet',
            self::Equipment => 'Equipment',
            self::Maintenance => 'Repairs and maintenance',
            self::Cleaning => 'Cleaning and supplies',
            self::Marketing => 'Marketing',
            self::Taxes => 'Taxes and fees',
            self::Other => 'Other',
        };
    }
}
