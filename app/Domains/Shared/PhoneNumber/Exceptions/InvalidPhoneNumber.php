<?php

declare(strict_types=1);

namespace App\Domains\Shared\PhoneNumber\Exceptions;

use InvalidArgumentException;

final class InvalidPhoneNumber extends InvalidArgumentException
{
    public static function cannotParse(string $input): self
    {
        return new self("Cannot read [{$input}] as a phone number. Expected an Ethiopian number like 0911 234 567, or an international number starting with +.");
    }
}
