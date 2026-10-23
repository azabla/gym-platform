<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Memberships\Models\Plan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Plans\StorePlanRequest;
use App\Http\Requests\Api\V1\Plans\UpdatePlanRequest;
use App\Http\Resources\V1\PlanResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PlanController extends Controller
{
    /**
     * The plans on sale at the gym, in alphabetical order.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Plan::class);

        return PlanResource::collection(Plan::query()->with('branches')->orderBy('name')->get());
    }

    /**
     * Create a plan, valid at all branches unless branches are chosen.
     */
    public function store(StorePlanRequest $request): PlanResource
    {
        $plan = DB::transaction(function () use ($request): Plan {
            $plan = Plan::query()->create($request->planDetails());
            $this->applyChosenBranches($plan, $request);

            return $plan;
        });

        return new PlanResource($plan->load('branches'));
    }

    /**
     * One plan of the gym.
     */
    public function show(Plan $plan): PlanResource
    {
        Gate::authorize('view', $plan);

        return new PlanResource($plan->load('branches'));
    }

    /**
     * Change the details that are sent. Memberships already sold keep their
     * price and branches.
     */
    public function update(UpdatePlanRequest $request, Plan $plan): PlanResource
    {
        DB::transaction(function () use ($request, $plan): void {
            $plan->update($request->planDetails());
            $this->applyChosenBranches($plan, $request);
        });

        return new PlanResource($plan->load('branches'));
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

    private function applyChosenBranches(Plan $plan, StorePlanRequest $request): void
    {
        if (! $request->choosesBranches()) {
            return;
        }

        $branches = $request->chosenBranches();

        if ($branches === null) {
            $plan->makeAvailableAtAllBranches();
        } else {
            $plan->makeAvailableOnlyAt(...$branches);
        }
    }
}
