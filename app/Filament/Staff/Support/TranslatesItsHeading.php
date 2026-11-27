<?php

declare(strict_types=1);

namespace App\Filament\Staff\Support;

use Illuminate\Contracts\Support\Htmlable;

/**
 * Gives a chart its heading and description in the staff member's language
 * (ADR 0041), passing the English through lang/am.json.
 */
trait TranslatesItsHeading
{
    public function getHeading(): string|Htmlable|null
    {
        $heading = parent::getHeading();

        return is_string($heading) ? __($heading) : $heading;
    }

    public function getDescription(): string|Htmlable|null
    {
        $description = parent::getDescription();

        return is_string($description) ? __($description) : $description;
    }
}
