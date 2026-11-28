<?php

declare(strict_types=1);

namespace App\Filament\Staff\Support;

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\PlatformBilling\Models\GymInvoice;
use App\Filament\Staff\Pages\PlatformBill;
use Illuminate\Support\HtmlString;

/**
 * A line across the top of every page, for the gym's owners only, while an
 * invoice from the platform is waiting to be paid: when it is due, and, once
 * overdue, the day it pauses the gym (ADR 0045).
 */
final class PlatformBillBanner
{
    public static function html(): ?HtmlString
    {
        $currentStaff = app(CurrentStaff::class);

        if (! $currentStaff->has() || $currentStaff->get()->role !== StaffRole::Owner) {
            return null;
        }

        $gym = app(CurrentOrganization::class)->get();
        $invoice = GymInvoice::query()->open()->orderBy('due_on')->first();

        if ($invoice === null) {
            return null;
        }

        $overdue = $invoice->isOverdueOn($gym->today());
        $text = $overdue
            ? __('Platform invoice :invoice of :amount was due on :due_on. Unless it is paid, the gym is paused on :pauses_on.', [
                'invoice' => $invoice->reference(),
                'amount' => $invoice->balance()->format(),
                'due_on' => Dates::short($invoice->due_on),
                'pauses_on' => Dates::short($invoice->pauses_on),
            ])
            : __('Platform invoice :invoice of :amount is due on :due_on.', [
                'invoice' => $invoice->reference(),
                'amount' => $invoice->balance()->format(),
                'due_on' => Dates::short($invoice->due_on),
            ]);

        return new HtmlString(view('staff-panel.bill-banner', [
            'text' => $text,
            'overdue' => $overdue,
            'url' => PlatformBill::getUrl(),
        ])->render());
    }
}
