<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DiscountGroups\StoreDiscountGroupRequest;
use App\Http\Requests\Api\V1\DiscountGroups\UpdateDiscountGroupRequest;
use App\Http\Resources\V1\DiscountGroupResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DiscountGroupController extends Controller
{
    /**
     * The gym's discount groups, archived ones included, in alphabetical
     * order, each with how many members are in it.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', DiscountGroup::class);

        return DiscountGroupResource::collection(
            DiscountGroup::query()->withTrashed()->withCount('memberDiscounts')->orderBy('name')->get(),
        );
    }

    /**
     * Create a discount group, such as "Student" for 10% off.
     */
    public function store(StoreDiscountGroupRequest $request, CurrentStaff $currentStaff): DiscountGroupResource
    {
        $discountGroup = new DiscountGroup($request->groupDetails());
        $discountGroup->organization()->associate($currentStaff->get()->organization_id);
        $discountGroup->createdBy()->associate($currentStaff->get());
        $discountGroup->save();

        return new DiscountGroupResource($discountGroup);
    }

    /**
     * One discount group of the gym, archived or not.
     */
    public function show(DiscountGroup $discountGroup): DiscountGroupResource
    {
        Gate::authorize('view', $discountGroup);

        return new DiscountGroupResource($discountGroup);
    }

    /**
     * Change the name or the discount. Memberships already sold at the
     * group's price keep it.
     */
    public function update(UpdateDiscountGroupRequest $request, DiscountGroup $discountGroup): DiscountGroupResource
    {
        $discountGroup->update($request->groupDetails());

        return new DiscountGroupResource($discountGroup);
    }

    /**
     * Archive the group: its members pay full price again, though they stay
     * in it, and it can be restored.
     */
    public function archive(DiscountGroup $discountGroup): DiscountGroupResource
    {
        Gate::authorize('delete', $discountGroup);

        $discountGroup->delete();

        return new DiscountGroupResource($discountGroup);
    }

    public function restore(DiscountGroup $discountGroup): DiscountGroupResource
    {
        Gate::authorize('restore', $discountGroup);

        $discountGroup->restore();

        return new DiscountGroupResource($discountGroup);
    }
}
