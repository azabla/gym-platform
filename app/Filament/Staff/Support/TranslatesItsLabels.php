<?php

declare(strict_types=1);

namespace App\Filament\Staff\Support;

use Illuminate\Support\Str;

/**
 * Gives a resource its labels in the staff member's language (ADR 0041):
 * Filament makes them in English from the model's name ("member",
 * "members"), and these pass them through lang/am.json.
 *
 * The plural is made from the English singular before it is translated, so
 * Amharic gets its own plural (አባላት), never an English "s" on the Amharic
 * singular.
 */
trait TranslatesItsLabels
{
    public static function getModelLabel(): string
    {
        return __(parent::getModelLabel());
    }

    public static function getPluralModelLabel(): string
    {
        $english = static::$pluralModelLabel ?? static::getPluralLabel() ?? Str::plural(parent::getModelLabel());

        return __($english);
    }

    public static function getNavigationLabel(): string
    {
        return __(parent::getNavigationLabel());
    }
}
