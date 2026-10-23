<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Tenancy;

use App\Domains\Organizations\Tenancy\Exceptions\NoCurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits queries on organization-owned models to the current organization.
 *
 * Fails closed: with no current organization the query throws instead of
 * silently returning every organization's rows.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class OrganizationScope implements Scope
{
    /**
     * Resolves CurrentOrganization on every query: a global scope is created
     * once per process, but the current organization changes per request and job.
     *
     * @param  Builder<TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $currentOrganization = app(CurrentOrganization::class);

        if (! $currentOrganization->has()) {
            throw NoCurrentOrganization::toQuery($model::class);
        }

        $builder->where($model->qualifyColumn('organization_id'), $currentOrganization->id());
    }
}
