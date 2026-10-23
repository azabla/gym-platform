<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Memberships\Models\Plan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Plans\StorePlanRequest;
use App\Http\Requests\Api\V1\Plans\UpdatePlanRequest;
use App\Http\Resources\V1\PlanResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PlanController extends Controller
{
    /**
     * The plans on sale at the gym, in alphabetical order.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Plan::class);

        return PlanResource::collection(Plan::query()->orderBy('name')->get());
    }

    /**
     * Create a plan.
     */
    public function store(StorePlanRequest $request): PlanResource
    {
        return new PlanResource(Plan::query()->create($request->planDetails()));
    }

    /**
     * One plan of the gym.
     */
    public function show(Plan $plan): PlanResource
    {
        Gate::authorize('view', $plan);

        return new PlanResource($plan);
    }

    /**
     * Change the details that are sent. Memberships already sold keep their price.
     */
    public function update(UpdatePlanRequest $request, Plan $plan): PlanResource
    {
        $plan->update($request->planDetails());

        return new PlanResource($plan);
    }

    /**
     * Archive the plan, so it can't be sold any more. Memberships already sold keep working.
     */
    public function destroy(Plan $plan): Response
    {
        Gate::authorize('delete', $plan);

        $plan->delete();

        return response()->noContent();
    }
}
