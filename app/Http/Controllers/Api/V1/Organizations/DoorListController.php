<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Attendance\DoorList\DoorList;
use App\Domains\Attendance\DoorList\DoorListSigner;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DoorListController extends Controller
{
    /**
     * What the staff app needs to let members in while offline, for today and
     * tomorrow (UTC), at the staff member's branches (or ?branch_id=): each
     * active credential's day keys and what its membership allows each day,
     * and each branch's hours. "list" is the JSON text the Ed25519
     * "signature" covers, so check the signature on those exact bytes before
     * reading it. Refresh it whenever online. See ADR 0038.
     */
    public function show(Request $request, CurrentStaff $currentStaff, DoorList $doorList, DoorListSigner $signer): JsonResponse
    {
        Gate::authorize('create', CheckIn::class);

        $staff = $currentStaff->get();
        $branches = $staff->branchesWorkedAt()
            ->when($request->filled('branch_id'), fn ($branches) => $branches->filter(fn (Branch $branch): bool => $branch->id === $request->integer('branch_id')))
            ->values();

        abort_if($branches->isEmpty(), 404, 'You don\'t work at this branch.');

        $list = (string) json_encode(
            $doorList->build($staff->organization, $branches, CarbonImmutable::now()),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return response()->json(['data' => [
            'list' => $list,
            'signature' => $signer->sign($list),
            'key_id' => $signer->keyId(),
        ]]);
    }

    /**
     * The public key door lists are signed with (Ed25519, base64), for setting
     * up the staff app, which should keep it rather than ask each time.
     */
    public function publicKey(DoorListSigner $signer): JsonResponse
    {
        Gate::authorize('create', CheckIn::class);

        return response()->json(['data' => [
            'algorithm' => 'Ed25519',
            'public_key' => $signer->publicKey(),
            'key_id' => $signer->keyId(),
        ]]);
    }
}
