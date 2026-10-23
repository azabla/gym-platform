<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Tenancy\Exceptions;

use LogicException;

final class OrganizationMismatch extends LogicException
{
    /** @param class-string $model */
    public static function whileCreating(string $model, int $organizationId, int $currentOrganizationId): self
    {
        return new self("Cannot create [{$model}] for organization {$organizationId} while acting as organization {$currentOrganizationId}.");
    }
}
