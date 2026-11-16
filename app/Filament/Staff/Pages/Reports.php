<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Filament\Staff\Actions\DownloadReportAction;
use App\Filament\Staff\NavigationGroup;
use App\Reports\CheckInsReport;
use App\Reports\DiscountsReport;
use App\Reports\MembershipsReport;
use App\Reports\MembersReport;
use App\Reports\PaymentsReport;
use App\Reports\ReportPeriod;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Every report in one place, each a CSV file for a period and a branch, as
 * the lists and the API give them (ADR 0024, ADR 0032 and ADR 0033). A
 * report shows only to the roles allowed it.
 */
class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Reports;

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Reports';

    protected ?string $subheading = 'Download any report as a CSV file, for a period and one of your branches or all of them. It opens in Excel or Google Sheets.';

    public static function canAccess(): bool
    {
        return collect([Payment::class, Membership::class, CheckIn::class, Member::class])
            ->contains(fn (string $model): bool => Gate::allows('export', $model));
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                self::card(
                    'Payments',
                    'Every payment and refund taken in the period, with method, transaction number, receipt and who took it.',
                    Heroicon::OutlinedBanknotes,
                    DownloadReportAction::make('downloadPayments')
                        ->report(Payment::class, fn (ReportPeriod $period): PaymentsReport => new PaymentsReport($period)),
                    Payment::class,
                ),
                self::card(
                    'Memberships sold',
                    'Memberships sold in the period, at the desk or in the app, with price, what was paid and what is still owed.',
                    Heroicon::OutlinedIdentification,
                    DownloadReportAction::make('downloadMemberships')
                        ->report(Membership::class, fn (ReportPeriod $period): MembershipsReport => new MembershipsReport($period)),
                    Membership::class,
                ),
                self::card(
                    'Discounts',
                    'Every membership sold below its price, with what came off and why: typed at the desk, a promo code or a discount group.',
                    Heroicon::OutlinedReceiptPercent,
                    DownloadReportAction::make('downloadDiscounts')
                        ->report(Membership::class, fn (ReportPeriod $period): DiscountsReport => new DiscountsReport($period)),
                    Membership::class,
                ),
                self::card(
                    'Check-ins',
                    'Every visit in the period, let in or turned away, with the time where the gym is and the reason.',
                    Heroicon::OutlinedArrowRightEndOnRectangle,
                    DownloadReportAction::make('downloadCheckIns')
                        ->report(CheckIn::class, fn (ReportPeriod $period): CheckInsReport => new CheckInsReport($period)),
                    CheckIn::class,
                ),
                self::card(
                    'Members',
                    'Every member as they are today: contact details, home branch, membership and what they owe.',
                    Heroicon::OutlinedUsers,
                    DownloadReportAction::make('downloadMembers')
                        ->withoutAPeriod()
                        ->report(Member::class, fn (ReportPeriod $period): MembersReport => new MembersReport($period->gym, $period->branchIds)),
                    Member::class,
                ),
            ]),
        ]);
    }

    /**
     * @param  class-string  $model
     */
    private static function card(string $heading, string $description, Heroicon $icon, DownloadReportAction $download, string $model): Section
    {
        return Section::make($heading)
            ->key((string) $download->getName())
            ->description($description)
            ->icon($icon)
            ->footerActions([$download->label('Download CSV')->color('primary')])
            ->visible(fn (): bool => Gate::allows('export', $model));
    }
}
