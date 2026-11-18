<?php

declare(strict_types=1);

namespace App\Domains\Shared\Language;

/**
 * The languages messages are written in: to members by SMS, and to staff in
 * their inbox and by SMS. The panel itself stays in English. See ADR 0034.
 *
 * The values are the names of the folders under lang/.
 */
enum Language: string
{
    case English = 'en';
    case Amharic = 'am';

    /**
     * The language's name in itself, as a person choosing it reads it.
     */
    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Amharic => 'አማርኛ',
        };
    }

    /**
     * The language of the code, such as "am" or "am-ET"; English when there
     * is none or it isn't one we write in.
     */
    public static function fromCode(?string $code): self
    {
        return self::tryFrom(strtolower(substr((string) $code, 0, 2))) ?? self::English;
    }

    /**
     * Every language's code.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(fn (self $language): string => $language->value, self::cases());
    }
}
