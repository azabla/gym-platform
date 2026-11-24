<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckIns\StoreOfflineCheckInsRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class OfflineCheckInController extends Controller
{
    /**
     * Record the scans the staff app made offline, oldest first, each at the
     * time it happened with what the door decided. Each scan answers on its
     * own: recorded (with server_disagrees and the server's reason when the
     * server would have decided otherwise), already_recorded for a retry, or
     * refused, with why, for a code that doesn't hold up. Send the same scan
     * ids again after a lost answer. See ADR 0038.
     */
    public function store(StoreOfflineCheckInsRequest $request, CurrentStaff $currentStaff): JsonResponse
    {
        $staff = $currentStaff->get();
        $branches = $staff->branchesWorkedAt()->keyBy('id');

        /** @var list<array{id: string, payload: string, branch_id: int|string, scanned_at: string, decision: string, reason_code?: string|null}> $scans */
        $scans = $request->validated('scans');
        $order = array_keys($scans);
        usort($order, fn (int $a, int $b): int => CarbonImmutable::parse($scans[$a]['scanned_at'])->getTimestamp() <=> CarbonImmutable::parse($scans[$b]['scanned_at'])->getTimestamp());

        $answers = [];

        foreach ($order as $index) {
            $scan = $scans[$index];
            $branch = $branches->get((int) $scan['branch_id']);

            $result = $branch instanceof Branch
                ? CheckIn::recordOffline(
                    $scan['id'],
                    $scan['payload'],
                    $branch,
                    $staff,
                    CarbonImmutable::parse($scan['scanned_at']),
                    $scan['decision'] === 'deny' ? CheckInDenial::from((string) $scan['reason_code']) : null,
                )
                : ['status' => 'refused', 'check_in' => null, 'message' => 'You don\'t work at this branch.'];

            $checkIn = $result['check_in'];

            $answers[$index] = [
                'id' => $scan['id'],
                'status' => $result['status'],
                'check_in_id' => $checkIn === null ? null : $checkIn->id,
                'server_disagrees' => $checkIn !== null && $checkIn->server_disagrees,
                'server_reason_code' => $checkIn === null ? null : $checkIn->server_reason_code,
                'message' => $result['message'],
            ];
        }

        ksort($answers);

        return response()->json(['data' => array_values($answers)]);
    }
}
