<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Identity\Models\PasswordLink;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Staff\StoreStaffRequest;
use App\Http\Requests\Api\V1\Staff\UpdateStaffRequest;
use App\Http\Resources\V1\StaffMemberResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The gym's staff, for owners and managers: add someone by phone, change
 * their role or branches, archive and restore them, and send their invite
 * again. See ADR 0019.
 */
class StaffController extends Controller
{
    public function __construct(private CurrentStaff $currentStaff) {}

    /**
     * The staff, oldest first, 25 per page; a manager limited to some
     * branches sees only staff at those branches. ?archived=1 lists the
     * archived staff instead.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Staff::class);

        $staff = Staff::query()
            ->atAnyOfBranches($this->currentStaff->get()->limitedToBranchIds())
            ->with(StaffMemberResource::RELATIONS)
            ->orderBy('id');

        if ($request->boolean('archived')) {
            $staff->onlyTrashed();
        }

        return StaffMemberResource::collection($staff->paginate(25)->withQueryString());
    }

    /**
     * Add someone to the staff by phone. Someone who already uses the app,
     * as a member or at another gym, keeps their account and name. They get
     * an SMS: a link to set their password, or, if they have one, that they
     * can sign in.
     */
    public function store(StoreStaffRequest $request): JsonResponse
    {
        $staff = DB::transaction(function () use ($request): Staff {
            $user = User::forPhone($request->phone(), $request->string('name')->toString());

            return Staff::add($user, $request->role(), ...$request->branches());
        });

        PasswordLink::invite($staff->user, $this->currentStaff->get()->organization->name);

        return (new StaffMemberResource($staff->load(StaffMemberResource::RELATIONS)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Staff $staff): StaffMemberResource
    {
        Gate::authorize('view', $staff);

        return new StaffMemberResource($staff->load(StaffMemberResource::RELATIONS));
    }

    /**
     * Change the role, the branches, or both; what isn't sent stays.
     */
    public function update(UpdateStaffRequest $request, Staff $staff): StaffMemberResource
    {
        $staff->reassign($request->role(), ...$request->branches());

        return new StaffMemberResource($staff->refresh()->load(StaffMemberResource::RELATIONS));
    }

    /**
     * Archive the staff member. They lose the gym on their next request, and
     * what they recorded still names them.
     */
    public function destroy(Staff $staff): Response
    {
        Gate::authorize('delete', $staff);

        $staff->archive();

        return response()->noContent();
    }

    public function restore(Staff $staff): StaffMemberResource
    {
        Gate::authorize('restore', $staff);

        $staff->restore();

        return new StaffMemberResource($staff->load(StaffMemberResource::RELATIONS));
    }

    /**
     * Send the invite again, with a new link; earlier links stop working.
     */
    public function invite(Staff $staff): Response
    {
        Gate::authorize('update', $staff);

        PasswordLink::invite($staff->user, $this->currentStaff->get()->organization->name);

        return response()->noContent(Response::HTTP_ACCEPTED);
    }
}
