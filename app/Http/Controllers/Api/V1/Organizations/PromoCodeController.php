<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PromoCodes\StorePromoCodeRequest;
use App\Http\Requests\Api\V1\PromoCodes\UpdatePromoCodeRequest;
use App\Http\Resources\V1\PromoCodeResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PromoCodeController extends Controller
{
    /**
     * The gym's promo codes, archived ones included, newest first, each with
     * how many times it has been used.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PromoCode::class);

        return PromoCodeResource::collection(
            PromoCode::query()->withTrashed()->with('plans')->withCount('memberships')->latest('id')->get(),
        );
    }

    /**
     * Create a code, for every plan unless plans are chosen.
     */
    public function store(StorePromoCodeRequest $request, CurrentStaff $currentStaff): PromoCodeResource
    {
        $promoCode = DB::transaction(function () use ($request, $currentStaff): PromoCode {
            $promoCode = new PromoCode($request->codeDetails());
            $promoCode->organization()->associate($currentStaff->get()->organization_id);
            $promoCode->createdBy()->associate($currentStaff->get());
            $promoCode->save();
            $this->applyChosenPlans($promoCode, $request);

            return $promoCode;
        });

        return new PromoCodeResource($promoCode->load('plans'));
    }

    /**
     * One promo code of the gym, archived or not.
     */
    public function show(PromoCode $promoCode): PromoCodeResource
    {
        Gate::authorize('view', $promoCode);

        return new PromoCodeResource($promoCode->load('plans'));
    }

    /**
     * Change the details that are sent. Memberships already sold with the
     * code keep their price.
     */
    public function update(UpdatePromoCodeRequest $request, PromoCode $promoCode): PromoCodeResource
    {
        DB::transaction(function () use ($request, $promoCode): void {
            $promoCode->update($request->codeDetails());
            $this->applyChosenPlans($promoCode, $request);
        });

        return new PromoCodeResource($promoCode->load('plans'));
    }

    /**
     * Archive the code, so it can't be used any more. Memberships sold with
     * it keep their price.
     */
    public function archive(PromoCode $promoCode): PromoCodeResource
    {
        Gate::authorize('delete', $promoCode);

        $promoCode->delete();

        return new PromoCodeResource($promoCode->load('plans'));
    }

    /**
     * Bring an archived code back, so it can be used again within its limits.
     */
    public function restore(PromoCode $promoCode): PromoCodeResource
    {
        Gate::authorize('restore', $promoCode);

        $promoCode->restore();

        return new PromoCodeResource($promoCode->load('plans'));
    }

    private function applyChosenPlans(PromoCode $promoCode, StorePromoCodeRequest $request): void
    {
        if (! $request->choosesPlans()) {
            return;
        }

        $plans = $request->chosenPlans();

        if ($plans === null) {
            $promoCode->makeAvailableForAllPlans();
        } else {
            $promoCode->makeAvailableOnlyFor(...$plans);
        }
    }
}
