<?php

declare(strict_types=1);

namespace App\Domains\Members\Exceptions;

use LogicException;

final class NoCurrentMember extends LogicException
{
    public static function isSet(): self
    {
        return new self('No current member is set. The members app\'s gym routes must use the member middleware.');
    }
}
