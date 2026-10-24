<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Money\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Members\StoreMemberRequest;
use App\Http\Requests\Api\V1\Members\UpdateMemberRequest;
use App\Http\Resources\V1\MemberResource;
use App\Http\Resources\V1\MembershipResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class MemberController extends Controller
{
    public function __construct(private CurrentOrganization $currentOrganization) {}

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

        $page = $members->paginate(25)->withQueryString();
        $this->attachMembershipsAtAGlance($page->getCollection());
        $this->attachBalancesDue($page->getCollection());

        return MemberResource::collection($page);
    }

    /**
     * Register a member at a branch the staff member works at.
     */
    public function store(StoreMemberRequest $request): MemberResource
    {
        $member = new Member($request->safe()->except('home_branch_id'));
        $member->homeBranch()->associate(Branch::query()->findOrFail($request->integer('home_branch_id')));
        $member->save();

        return $this->resourceFor($member);
    }

    /**
     * One member of the gym.
     */
    public function show(Member $member): MemberResource
    {
        Gate::authorize('view', $member);

        return $this->resourceFor($member);
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

        return $this->resourceFor($member);
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

    private function resourceFor(Member $member): MemberResource
    {
        $member->load('homeBranch');
        $this->attachMembershipsAtAGlance(new Collection([$member]));
        $this->attachBalancesDue(new Collection([$member]));

        return new MemberResource($member);
    }

    /**
     * Give each member their membership at a glance (see MemberResource), with
     * its balance. It is looked up here, in the HTTP layer, because the Members
     * domain doesn't depend on Memberships.
     *
     * @param  Collection<int, Member>  $members
     */
    private function attachMembershipsAtAGlance(Collection $members): void
    {
        $memberships = Membership::atAGlanceFor(
            $members->map(fn (Member $member): int => $member->id)->all(),
            $this->currentOrganization->get()->today(),
        );
        MembershipResource::attachBalances(...$memberships->values()->all());

        foreach ($members as $member) {
            $member->setRelation('membershipAtAGlance', $memberships->get($member->id));
        }
    }

    /**
     * Give each member what they still owe across their memberships, looked up
     * in Billing.
     *
     * @param  Collection<int, Member>  $members
     */
    private function attachBalancesDue(Collection $members): void
    {
        $balancesDue = Payment::balancesDueOf($members->map(fn (Member $member): int => $member->id)->all());

        foreach ($members as $member) {
            $member->setRelation('balanceDue', $balancesDue[$member->id] ?? Money::zero());
        }
    }
}
