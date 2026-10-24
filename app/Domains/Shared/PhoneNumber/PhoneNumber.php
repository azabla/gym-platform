<?php

declare(strict_types=1);

namespace App\Domains\Shared\PhoneNumber;

use App\Domains\Shared\PhoneNumber\Exceptions\InvalidPhoneNumber;

/**
 * A phone number in the international E.164 format, such as +251911234567.
 *
 * Ethiopian numbers can be typed the way people write them. Numbers from other
 * countries need the international form with a leading "+".
 *
 * See docs/adr/0005-members-and-phone-numbers.md for the reasoning.
 */
final readonly class PhoneNumber
{
    /** Ethiopia's country calling code. */
    private const ETHIOPIA = '251';

    private function __construct(
        public string $e164,
    ) {}

    /**
     * Parse a phone number as people type it.
     *
     * Ethiopian numbers: "0911 23 45 67", "911234567", "+251 911 234 567",
     * "251911234567" and "+251 0911 234 567" all become +251911234567.
     * Other countries: "+44 7911 123456" becomes +447911123456.
     */
    public static function of(string $input): self
    {
        $compact = preg_replace('/[\s().-]/', '', $input) ?? '';

        if (preg_match('/^(?:\+?'.self::ETHIOPIA.'0?|0)?([1-9]\d{8})$/', $compact, $matches) === 1) {
            return new self('+'.self::ETHIOPIA.$matches[1]);
        }

        $isOtherCountry = preg_match('/^\+[1-9]\d{7,14}$/', $compact) === 1
            && ! str_starts_with($compact, '+'.self::ETHIOPIA);

        if ($isOtherCountry) {
            return new self($compact);
        }

        throw InvalidPhoneNumber::cannotParse($input);
    }

    /**
     * The number as people write it in Ethiopia, such as 0911234567, which is
     * the form Chapa expects. Null for a number from another country.
     */
    public function ethiopianNational(): ?string
    {
        $countryPrefix = '+'.self::ETHIOPIA;

        return str_starts_with($this->e164, $countryPrefix) ? '0'.substr($this->e164, strlen($countryPrefix)) : null;
    }
}
