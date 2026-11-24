<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Members\Photos\MemberPhoto;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\UpdateMyPhotoRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MyPhotoController extends Controller
{
    public function __construct(private MemberPhoto $memberPhoto) {}

    /**
     * Choose my photo, sent as multipart "photo", at every gym where my phone
     * is registered. The desk sees it at the door, and may replace it. See
     * ADR 0036.
     */
    public function update(UpdateMyPhotoRequest $request): Response
    {
        $imageBytes = $request->photoBytes();

        foreach (self::myMemberRecords($request) as $member) {
            $this->memberPhoto->store($member, $imageBytes);
        }

        return response()->noContent();
    }

    /**
     * Take my photo away at every gym.
     */
    public function destroy(Request $request): Response
    {
        foreach (self::myMemberRecords($request) as $member) {
            $this->memberPhoto->remove($member);
        }

        return response()->noContent();
    }

    /**
     * The signed-in phone's member record at each gym.
     *
     * @return Collection<int, Member>
     */
    private static function myMemberRecords(Request $request): Collection
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->phone === null) {
            return new Collection;
        }

        return Member::query()->acrossOrganizations()->where('phone', $user->phone)->get();
    }
}
