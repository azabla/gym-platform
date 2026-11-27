<?php

declare(strict_types=1);

namespace App\Filament\Staff\Support;

/**
 * Gives a resource its labels in the staff member's language (ADR 0041):
 * Filament makes them in English from the model's name ("member",
 * "members"), and these pass them through lang/am.json.
 */
trait TranslatesItsLabels
{
    public static function getModelLabel(): string
    {
        return __(parent::getModelLabel());
    }

    public static function getPluralModelLabel(): string
    {
        return __(parent::getPluralModelLabel());
    }

    public static function getNavigationLabel(): string
    {
        return __(parent::getNavigationLabel());
    }
}
