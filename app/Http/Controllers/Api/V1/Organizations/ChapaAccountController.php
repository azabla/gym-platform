<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Billing\ConnectChapaAccountRequest;
use App\Http\Resources\V1\ChapaAccountResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ChapaAccountController extends Controller
{
    /**
     * Whether the gym's Chapa account is connected, and with which kind of key.
     */
    public function show(): ChapaAccountResource
    {
        Gate::authorize('view', ChapaAccount::class);

        return new ChapaAccountResource(ChapaAccount::query()->with('connectedBy.user')->first());
    }

    /**
     * Connect the gym's own Chapa account with its secret key, or replace the
     * key. Responds 201 the first time, 200 when a key is replaced.
     */
    public function update(ConnectChapaAccountRequest $request, CurrentStaff $currentStaff): ChapaAccountResource
    {
        $account = ChapaAccount::connect($request->string('secret_key')->toString(), $currentStaff->get());

        return new ChapaAccountResource($account->load('connectedBy.user'));
    }

    /**
     * Disconnect the gym's Chapa account. Its key is deleted.
     */
    public function destroy(): Response
    {
        Gate::authorize('disconnect', ChapaAccount::class);

        ChapaAccount::query()->delete();

        return response()->noContent();
    }
}
