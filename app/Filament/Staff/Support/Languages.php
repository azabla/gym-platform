<?php

declare(strict_types=1);

namespace App\Filament\Staff\Support;

use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Language\Language;

/**
 * The message languages as the panel offers them (ADR 0034): named in
 * English, as the panel is, with each language's own name beside it.
 */
final class Languages
{
    /**
     * @return array<string, string> labels by code
     */
    public static function options(): array
    {
        $options = [];

        foreach (Language::cases() as $language) {
            $options[$language->value] = self::label($language);
        }

        return $options;
    }

    public static function label(Language $language): string
    {
        return match ($language) {
            Language::English => 'English',
            Language::Amharic => 'Amharic ('.$language->label().')',
        };
    }

    /**
     * What a member without their own language gets, such as "Gym default (Amharic)".
     */
    public static function gymDefault(): string
    {
        return 'Gym default ('.self::label(app(CurrentOrganization::class)->get()->messageLanguage()).')';
    }
}
