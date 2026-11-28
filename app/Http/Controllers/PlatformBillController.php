<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\PlatformBilling\Models\GymInvoice;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * The platform's invoice to a gym as a page to read or print (ADR 0045): in
 * English for platform admins, and in their own language for the gym's
 * owners, even while the gym is paused for it.
 */
class PlatformBillController extends Controller
{
    public function show(int $invoice): View
    {
        $found = GymInvoice::query()->acrossOrganizations()->findOrFail($invoice);
        $admin = Auth::guard('admin_panel')->user();
        $staffUser = Auth::guard('staff_panel')->user();

        if ($admin instanceof User && $admin->is_platform_admin) {
            app()->setLocale('en');
        } elseif ($staffUser instanceof User && self::owns($staffUser, $found->organization_id)) {
            app()->setLocale($staffUser->messageLanguage()->value);
        } else {
            abort(403);
        }

        return view('platform-bill.invoice', [
            'invoice' => $found,
            'gym' => Organization::query()->findOrFail($found->organization_id),
        ]);
    }

    private static function owns(User $user, int $gymId): bool
    {
        return Staff::query()->acrossOrganizations()
            ->where('organization_id', $gymId)
            ->where('user_id', $user->getKey())
            ->where('role', StaffRole::Owner)
            ->exists();
    }
}
