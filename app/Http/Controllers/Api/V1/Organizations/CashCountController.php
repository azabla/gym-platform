<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Billing\Models\CashCount;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payments\CloseCashCountRequest;
use App\Http\Resources\V1\CashCountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Cash counts in the staff app (ADR 0046), as in the panel: everyone closes
 * their own; owners, managers and accountants see everyone's at their
 * branches; owners and managers mark them checked.
 */
class CashCountController extends Controller
{
    /**
     * Counts, newest first: one's own, or everyone's at one's branches for
     * those who see them. Filters: ?branch_id=, ?staff_id=, ?checked=0|1.
     */
    public function index(Request $request, CurrentStaff $currentStaff): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CashCount::class);

        $staff = $currentStaff->get();
        $branchIds = $staff->limitedToBranchIds();

        $counts = CashCount::query()
            ->with(['branch', 'staff.user'])
            ->when(! Gate::allows('seeEveryones', CashCount::class), fn ($query) => $query->where('staff_id', $staff->id))
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('staff_id'), fn ($query) => $query->where('staff_id', $request->integer('staff_id')))
            ->when($request->filled('checked'), fn ($query) => $request->boolean('checked') ? $query->whereNotNull('checked_at') : $query->whereNull('checked_at'))
            ->orderByDesc('closed_at')
            ->paginate(50);

        return CashCountResource::collection($counts);
    }

    /**
     * The cash the staff member should have at each branch they work at,
     * since their last count there.
     */
    public function expected(CurrentStaff $currentStaff): JsonResponse
    {
        Gate::authorize('create', CashCount::class);

        $staff = $currentStaff->get();
        $branches = [];

        foreach ($staff->branchesWorkedAt() as $branch) {
            $branches[] = ['branch' => ['id' => $branch->id, 'name' => $branch->name], 'expected' => CashCount::expectedFor($staff, $branch)];
        }

        return response()->json(['data' => $branches]);
    }

    /**
     * Close the staff member's count at a branch. A difference needs a note;
     * with nothing to count the answer is a 422.
     */
    public function store(CloseCashCountRequest $request, CurrentStaff $currentStaff): JsonResponse
    {
        $count = CashCount::close($currentStaff->get(), $request->branch(), Money::of($request->string('counted')->toString()), $request->input('note'));

        return (new CashCountResource($count->load(['branch', 'staff.user'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Mark a count checked, with an optional note.
     */
    public function check(Request $request, CashCount $cashCount, CurrentStaff $currentStaff): CashCountResource
    {
        Gate::authorize('check', $cashCount);

        $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $cashCount->check($currentStaff->get(), $request->input('note'));

        return new CashCountResource($cashCount->refresh()->load(['branch', 'staff.user']));
    }
}
