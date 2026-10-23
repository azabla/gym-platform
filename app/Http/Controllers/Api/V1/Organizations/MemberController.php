<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Members\StoreMemberRequest;
use App\Http\Requests\Api\V1\Members\UpdateMemberRequest;
use App\Http\Resources\V1\MemberResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class MemberController extends Controller
{
    /**
     * The gym's members in alphabetical order, 25 per page. The optional
     * ?search= matches part of a name or the digits of a phone number.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Member::class);

        $members = Member::query()->with('homeBranch')->orderBy('full_name');

        $search = $request->string('search')->trim()->toString();

        if ($search !== '') {
            $members->search($search);
        }

        return MemberResource::collection($members->paginate(25)->withQueryString());
    }

    /**
     * Register a member at a branch the staff member works at.
     */
    public function store(StoreMemberRequest $request): MemberResource
    {
        $member = new Member($request->safe()->except('home_branch_id'));
        $member->homeBranch()->associate(Branch::query()->findOrFail($request->integer('home_branch_id')));
        $member->save();

        return new MemberResource($member->load('homeBranch'));
    }

    /**
     * One member of the gym.
     */
    public function show(Member $member): MemberResource
    {
        Gate::authorize('view', $member);

        return new MemberResource($member->load('homeBranch'));
    }

    /**
     * Change the details that are sent; the rest stay as they are.
     */
    public function update(UpdateMemberRequest $request, Member $member): MemberResource
    {
        $member->fill($request->safe()->except('home_branch_id'));

        if ($request->has('home_branch_id')) {
            $member->homeBranch()->associate(Branch::query()->findOrFail($request->integer('home_branch_id')));
        }

        $member->save();

        return new MemberResource($member->load('homeBranch'));
    }

    /**
     * Archive the member. The record is kept for history.
     */
    public function destroy(Member $member): Response
    {
        Gate::authorize('delete', $member);

        $member->delete();

        return response()->noContent();
    }
}
