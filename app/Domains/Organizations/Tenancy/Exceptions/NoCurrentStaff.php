<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Tenancy\Exceptions;

use LogicException;

final class NoCurrentStaff extends LogicException
{
    public static function isSet(): self
    {
        return new self('No current staff member is set. Organization routes must use the organization middleware.');
    }
}
