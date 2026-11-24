<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Organizations\Models\BranchClosure;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Branches\StoreBranchClosureRequest;
use App\Http\Resources\V1\BranchClosureResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class BranchClosureController extends Controller
{
    /**
     * Closures at the branches the staff member works at, going on or still
     * to come, soonest first. Filters: ?branch_id=, and ?include_past=1 for
     * ended ones too. See ADR 0037.
     */
    public function index(Request $request, CurrentStaff $currentStaff): AnonymousResourceCollection
    {
        $staff = $currentStaff->get();
        $branchIds = $staff->branchesWorkedAt()->modelKeys();

        $closures = BranchClosure::query()
            ->with('branch')
            ->whereIn('branch_id', $branchIds)
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->unless($request->boolean('include_past'), fn ($query) => $query->notEndedBy($staff->organization->today()))
            ->orderBy('starts_on')
            ->orderBy('branch_id')
            ->paginate(50);

        return BranchClosureResource::collection($closures);
    }

    /**
     * Close one or more branches from one day to another, or open them other
     * hours, with a reason: one closure for each branch. Members of the
     * branches hear of it when it starts within 14 days.
     */
    public function store(StoreBranchClosureRequest $request, CurrentStaff $currentStaff): JsonResponse
    {
        $closures = BranchClosure::schedule(
            $currentStaff->get(),
            $request->branches()->all(),
            $request->startsOn(),
            $request->endsOn(),
            $request->string('reason')->toString(),
            $request->input('opens_at'),
            $request->input('closes_at'),
        );

        return BranchClosureResource::collection($closures->load('branch'))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Take a closure back: the branch keeps its weekly hours those days.
     */
    public function destroy(BranchClosure $branchClosure): Response
    {
        Gate::authorize('update', $branchClosure->branch);

        $branchClosure->delete();

        return response()->noContent();
    }
}
