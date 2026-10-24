<?php

namespace App\Http\Middleware;

use App\Domains\Members\CurrentMember;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acts as the gym whose slug is in the URL ({organization}), for a signed-in
 * user who is a member there: the member record with the user's phone number,
 * if it isn't archived. See ADR 0010.
 *
 * Like ActAsOrganization, it runs before route-model binding, an unknown slug
 * and a gym where the user isn't a member both get the same 404, and the slug
 * is then removed from the route parameters.
 */
class ActAsMember
{
    public function __construct(
        private CurrentOrganization $currentOrganization,
        private CurrentMember $currentMember,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $phone = $request->user()?->phone;

        $member = $phone === null ? null : Member::query()
            ->acrossOrganizations()
            ->where('phone', $phone)
            ->whereRelation('organization', 'slug', $request->route('organization'))
            ->with('organization')
            ->first();

        abort_if($member === null, Response::HTTP_NOT_FOUND);

        $this->currentOrganization->set($member->organization);
        $this->currentMember->set($member);
        $request->route()->forgetParameter('organization');

        return $next($request);
    }
}
