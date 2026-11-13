<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Branches\StoreBranchRequest;
use App\Http\Requests\Api\V1\Branches\UpdateBranchRequest;
use App\Http\Resources\V1\BranchResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class BranchController extends Controller
{
    /**
     * The branches the signed-in staff member works at, with their opening hours.
     */
    public function index(CurrentStaff $currentStaff): AnonymousResourceCollection
    {
        return BranchResource::collection($currentStaff->get()->branchesWorkedAt()->load('openingHours'));
    }

    /**
     * Add a branch to the gym, always open until it is given opening hours.
     * Owners only (ADR 0023).
     */
    public function store(StoreBranchRequest $request): BranchResource
    {
        $branch = Branch::query()->create($request->validated());

        return new BranchResource($branch->load('openingHours'));
    }

    /**
     * Change the branch's name, code or address.
     */
    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        $branch->update($request->validated());

        return new BranchResource($branch->load('openingHours'));
    }

    /**
     * One branch, if the signed-in staff member works at it.
     */
    public function show(Branch $branch): BranchResource
    {
        Gate::authorize('view', $branch);

        return new BranchResource($branch->load('openingHours'));
    }
}
