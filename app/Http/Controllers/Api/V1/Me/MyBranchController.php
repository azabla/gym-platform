<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Organizations\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BranchResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MyBranchController extends Controller
{
    /**
     * The gym's branches, with their opening hours, for the members app to
     * show where and when the member can train.
     */
    public function index(): AnonymousResourceCollection
    {
        return BranchResource::collection(Branch::query()->with('openingHours')->orderBy('name')->get());
    }
}
