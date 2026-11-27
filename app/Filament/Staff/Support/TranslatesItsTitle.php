<?php

declare(strict_types=1);

namespace App\Filament\Staff\Support;

use Illuminate\Contracts\Support\Htmlable;

/**
 * Gives a custom page its title, menu label and subheading in the staff
 * member's language (ADR 0041), passing the English through lang/am.json.
 */
trait TranslatesItsTitle
{
    public function getTitle(): string|Htmlable
    {
        $title = parent::getTitle();

        return is_string($title) ? __($title) : $title;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $subheading = parent::getSubheading();

        return is_string($subheading) ? __($subheading) : $subheading;
    }

    public static function getNavigationLabel(): string|Htmlable
    {
        $label = parent::getNavigationLabel();

        return is_string($label) ? __($label) : $label;
    }
}
