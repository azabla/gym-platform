<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Models\GymInvoice;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * The platform's invoice to a gym as a page to read or print (ADR 0045), in
 * English for platform admins.
 */
class PlatformBillController extends Controller
{
    public function show(int $invoice): View
    {
        $found = GymInvoice::query()->acrossOrganizations()->findOrFail($invoice);
        $admin = Auth::guard('admin_panel')->user();

        abort_unless($admin instanceof User && $admin->is_platform_admin, 403);

        app()->setLocale('en');

        return view('platform-bill.invoice', [
            'invoice' => $found,
            'gym' => Organization::query()->findOrFail($found->organization_id),
        ]);
    }
}
