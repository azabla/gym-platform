<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Organizations\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Branches\UpdateOpeningHoursRequest;
use App\Http\Resources\V1\OpeningHoursResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class BranchOpeningHoursController extends Controller
{
    /**
     * The branch's opening hours, Monday first. An empty list means always open.
     */
    public function show(Branch $branch): AnonymousResourceCollection
    {
        Gate::authorize('view', $branch);

        return OpeningHoursResource::collection($branch->openingHours);
    }

    /**
     * Replace the branch's whole week. Check-ins outside it are turned away
     * with OUTSIDE_HOURS.
     */
    public function update(UpdateOpeningHoursRequest $request, Branch $branch): AnonymousResourceCollection
    {
        $branch->replaceOpeningHours($request->week());

        return OpeningHoursResource::collection($branch->openingHours);
    }
}
