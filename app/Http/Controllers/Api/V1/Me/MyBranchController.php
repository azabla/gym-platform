<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BranchResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MyBranchController extends Controller
{
    /**
     * The gym's branches, with their opening hours and closures from today to
     * 60 days ahead, for the members app to show where and when the member
     * can train (ADR 0037).
     */
    public function index(CurrentOrganization $currentOrganization): AnonymousResourceCollection
    {
        $today = $currentOrganization->get()->today();

        return BranchResource::collection(Branch::query()->with(Branch::withHoursAndClosuresFrom($today))->orderBy('name')->get());
    }
}
