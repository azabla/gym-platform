<?php

declare(strict_types=1);

namespace App\Domains\Imports;

use App\Domains\Billing\Models\Payment;
use App\Domains\Imports\Enums\MemberImportRowOutcome;
use App\Domains\Imports\Enums\MemberImportStatus;
use App\Domains\Imports\Exceptions\RowRefused;
use App\Domains\Imports\Models\MemberImport;
use App\Domains\Imports\Models\MemberImportRow;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Imports the ready rows of a checked member import, a few at a time, each
 * in its own transaction: a row that fails doesn't undo the others, the
 * gym's receipt counter is never held for long, and running it again after
 * a stop picks up where it left. Each row is checked again first, as the gym
 * may have changed since. Run inside the import's gym. See ADR 0044.
 */
final class MemberImporter
{
    /**
     * Import up to the given number of ready rows. Whether any are left.
     */
    public function importNext(MemberImport $import, int $rows = 100): bool
    {
        if ($import->status === MemberImportStatus::Checked) {
            $import->status = MemberImportStatus::Importing;
            $import->started_at = CarbonImmutable::now();
            $import->save();
        }

        if ($import->status !== MemberImportStatus::Importing) {
            return false;
        }

        $gym = Organization::query()->findOrFail($import->organization_id);
        $check = new RowCheck($gym->today());
        $staff = $import->uploadedBy;

        $import->rows()
            ->where('outcome', MemberImportRowOutcome::Ready)
            ->orderBy('line_number')
            ->limit($rows)
            ->get()
            ->each(fn (MemberImportRow $row) => $this->importRow($row, $check, $staff));

        $left = $import->rows()->where('outcome', MemberImportRowOutcome::Ready)->exists();

        if (! $left) {
            $import->status = MemberImportStatus::Imported;
            $import->finished_at = CarbonImmutable::now();
        }

        $import->recount();

        return $left;
    }

    private function importRow(MemberImportRow $row, RowCheck $check, Staff $staff): void
    {
        $checked = $check->check($row->line_number, $row->cells);

        if (! $checked->isReady()) {
            $row->settle($checked->outcome === MemberImportRowOutcome::Duplicate ? MemberImportRowOutcome::Duplicate : MemberImportRowOutcome::Failed, $checked->reason);

            return;
        }

        try {
            $member = DB::transaction(fn (): Member => $this->bringIn($checked, $check, $staff));
        } catch (RowRefused $refusal) {
            $row->settle(MemberImportRowOutcome::Failed, $refusal);

            return;
        } catch (DomainException $refusal) {
            $row->settle(MemberImportRowOutcome::Failed, RowRefused::refused($refusal->getMessage()));

            return;
        } catch (QueryException $exception) {
            // Someone registered the same phone at the desk since the check.
            if ($exception->getCode() === '23505') {
                $row->settle(MemberImportRowOutcome::Duplicate, RowRefused::alreadyAMember((string) $checked->fullName));

                return;
            }

            throw $exception;
        }

        $row->settle(MemberImportRowOutcome::Added, member: $member);
    }

    /**
     * The member, their membership and what they paid, from a ready row.
     *
     * @throws RowRefused|DomainException
     */
    private function bringIn(CheckedRow $row, RowCheck $check, Staff $staff): Member
    {
        $member = new Member([
            'full_name' => $row->fullName,
            'phone' => $row->phone?->e164,
            'gender' => $row->gender,
            'date_of_birth' => $row->dateOfBirth,
        ]);
        $member->homeBranch()->associate($row->homeBranch);
        $member->save();

        if ($row->plan === null || $row->startsOn === null || $row->homeBranch === null) {
            return $member;
        }

        $check->stillFits($member, $row);
        $membership = Membership::bringIn($member, $row->plan, $row->startsOn, $row->endsOn, $staff);

        if ($row->amountPaid !== null) {
            Payment::bringIn($membership, $row->amountPaid, $row->homeBranch, $staff);
        }

        return $member;
    }
}
