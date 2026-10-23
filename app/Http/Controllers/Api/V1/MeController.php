<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Organizations\Models\Staff;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\StaffResource;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * The signed-in user and their staff role at every organization they work at.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $staff = Staff::query()
            ->acrossOrganizations()
            ->whereBelongsTo($user)
            ->with('organization')
            ->get();

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'staff' => StaffResource::collection($staff),
            ],
        ]);
    }
}
