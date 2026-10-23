<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Money\Money;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Me\MyGymResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MyGymController extends Controller
{
    /**
     * The gyms where the signed-in user's phone number is registered as a
     * member: the gym, the member record, the membership at a glance, and
     * what is still owed there. Each gym is read in turn, acting as that gym.
     */
    public function index(Request $request, CurrentOrganization $currentOrganization): AnonymousResourceCollection
    {
        $phone = $request->user()?->phone;

        if ($phone === null) {
            return MyGymResource::collection([]);
        }

        $members = Member::query()
            ->acrossOrganizations()
            ->where('phone', $phone)
            ->with('organization')
            ->orderBy('organization_id')
            ->get();

        foreach ($members as $member) {
            $currentOrganization->run($member->organization, function () use ($member): void {
                $member->load('homeBranch');
                $member->setRelation(
                    'membershipAtAGlance',
                    Membership::atAGlanceFor([$member->id], $member->organization->today())->get($member->id),
                );
                $member->setRelation('balanceDue', Payment::balancesDueOf([$member->id])[$member->id] ?? Money::zero());
            });
        }

        return MyGymResource::collection($members);
    }
}
