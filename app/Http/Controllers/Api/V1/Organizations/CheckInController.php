<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckIns\ListCheckInsRequest;
use App\Http\Requests\Api\V1\CheckIns\ScanCheckInRequest;
use App\Http\Requests\Api\V1\CheckIns\StoreDeskCheckInRequest;
use App\Http\Resources\V1\CheckInResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CheckInController extends Controller
{
    /**
     * The gym's check-ins, allowed and denied, newest first, 25 per page. The
     * optional filters are the day where the gym is (?on=), ?branch_id=,
     * ?result= and ?member_id=.
     */
    public function index(ListCheckInsRequest $request, CurrentOrganization $currentOrganization): AnonymousResourceCollection
    {
        $timezone = $currentOrganization->get()->timezone;

        $page = CheckIn::query()
            ->when($request->filled('on'), function ($query) use ($request, $timezone) {
                $day = CarbonImmutable::parse($request->string('on')->toString(), $timezone)->startOfDay();

                return $query->where('checked_in_at', '>=', $day->utc())->where('checked_in_at', '<', $day->addDay()->utc());
            })
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('result'), fn ($query) => $query->where('result', $request->string('result')->toString()))
            ->when($request->filled('member_id'), fn ($query) => $query->where('member_id', $request->integer('member_id')))
            ->orderByDesc('checked_in_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        CheckInResource::loadDetails(...$page->items());

        return CheckInResource::collection($page);
    }

    /**
     * Check in whoever shows the scanned QR code. A denial is a normal answer
     * at the door, so it answers 200 either way, with the decision and why.
     */
    public function scan(ScanCheckInRequest $request, CurrentStaff $currentStaff): JsonResponse
    {
        $checkIn = CheckIn::scan(
            payload: $request->string('payload')->toString(),
            branch: $request->branch(),
            scannedBy: $currentStaff->get(),
            idempotencyKey: $request->idempotencyKey(),
        );

        return $this->answer($checkIn);
    }

    /**
     * Check the member in by hand at the desk. Answers 200 like a scan.
     */
    public function store(StoreDeskCheckInRequest $request, Member $member, CurrentStaff $currentStaff): JsonResponse
    {
        return $this->answer(CheckIn::atDesk($member, $request->branch(), $currentStaff->get()));
    }

    private function answer(CheckIn $checkIn): JsonResponse
    {
        CheckInResource::loadDetails($checkIn);

        return (new CheckInResource($checkIn))->response()->setStatusCode(Response::HTTP_OK);
    }
}
