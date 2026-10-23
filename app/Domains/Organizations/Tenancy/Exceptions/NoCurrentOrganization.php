<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Tenancy\Exceptions;

use LogicException;

final class NoCurrentOrganization extends LogicException
{
    public static function isSet(): self
    {
        return new self('No current organization is set.');
    }

    /** @param class-string $model */
    public static function toQuery(string $model): self
    {
        return new self("Cannot query [{$model}] without a current organization. Run the code inside CurrentOrganization::run(), or use acrossOrganizations() for platform-wide queries.");
    }

    /** @param class-string $model */
    public static function toCreate(string $model): self
    {
        return new self("Cannot create [{$model}] without an organization. Run the code inside CurrentOrganization::run(), or create it through its organization.");
    }
}
