<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Tenancy;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\Exceptions\NoCurrentOrganization;
use App\Domains\Organizations\Tenancy\Exceptions\OrganizationMismatch;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For models owned by one organization (gym).
 *
 * Queries only see the current organization's rows, new rows are assigned to
 * the current organization, and creating a row for another organization while
 * acting as one is refused. See docs/adr/0003-multi-tenancy-with-organization-scoping.md.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model): void {
            $currentOrganization = app(CurrentOrganization::class);
            $organizationId = $model->getAttribute('organization_id');

            if ($organizationId === null) {
                if (! $currentOrganization->has()) {
                    throw NoCurrentOrganization::toCreate($model::class);
                }

                $model->setAttribute('organization_id', $currentOrganization->id());

                return;
            }

            if ($currentOrganization->has() && $organizationId !== $currentOrganization->id()) {
                throw OrganizationMismatch::whileCreating($model::class, $organizationId, $currentOrganization->id());
            }
        });
    }

    public function initializeBelongsToOrganization(): void
    {
        $this->mergeCasts(['organization_id' => 'integer']);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Drop the current-organization filter. For platform-wide code only.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function acrossOrganizations(Builder $query): void
    {
        $query->withoutGlobalScope(OrganizationScope::class);
    }
}
