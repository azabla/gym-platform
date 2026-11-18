<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Organizations\UpdateOrganizationSettingsRequest;
use App\Http\Resources\V1\OrganizationSettingsResource;
use Illuminate\Support\Facades\Gate;

class OrganizationSettingsController extends Controller
{
    public function __construct(private CurrentOrganization $currentOrganization) {}

    /**
     * The gym's settings, which every staff member sees.
     */
    public function show(): OrganizationSettingsResource
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('view', $organization);

        return new OrganizationSettingsResource($organization);
    }

    /**
     * Change the language the gym's members get messages in, unless a member
     * chose their own. Owners only. See ADR 0034.
     */
    public function update(UpdateOrganizationSettingsRequest $request): OrganizationSettingsResource
    {
        $organization = $this->currentOrganization->get();
        $organization->update($request->validated());

        return new OrganizationSettingsResource($organization);
    }
}
