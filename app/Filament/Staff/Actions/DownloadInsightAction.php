<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Insights\GymInsights;
use App\Insights\Insight;
use App\Reports\Csv;
use App\Reports\InsightReport;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download an insight as a CSV file, for the last 3, 6 or 12 months at one
 * of the staff member's branches or all of theirs, as the API gives it.
 * Shown only to the roles the insight is for (ADR 0040).
 */
class DownloadInsightAction extends Action
{
    protected Insight $insight;

    /** @var array{months?: int, branch_id?: int|null} the page's filters, chosen by default */
    protected array $chosen = [];

    public static function getDefaultName(): ?string
    {
        return 'downloadInsight';
    }

    public static function for(Insight $insight): static
    {
        return static::make('download'.str_replace('-', '', ucwords($insight->value, '-')))->insight($insight);
    }

    public function insight(Insight $insight): static
    {
        $this->insight = $insight;

        return $this;
    }

    /**
     * @param  array{months?: int, branch_id?: int|null}  $chosen
     */
    public function chosen(array $chosen): static
    {
        $this->chosen = $chosen;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Download CSV'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading(fn (): string => __('Download :insight', ['insight' => mb_strtolower(__($this->insight->label()))]))
            ->modalIcon(Heroicon::OutlinedArrowDownTray)
            ->modalSubmitActionLabel(__('Download'))
            ->visible(fn (): bool => Gate::allows('see-insight', $this->insight))
            ->schema(fn (): array => [
                Select::make('months')
                    ->label(__('Period'))
                    ->options(self::periods())
                    ->default(fn (): int => $this->chosen['months'] ?? 12)
                    ->required(),
                Select::make('branch_id')
                    ->label(__('Branch'))
                    ->placeholder(__('All my branches'))
                    ->default(fn (): ?int => $this->chosen['branch_id'] ?? null)
                    ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all()),
            ])
            ->action(fn (array $data): StreamedResponse => Csv::download(new InsightReport(
                $this->insight,
                app(CurrentOrganization::class)->get(),
                (int) $data['months'],
                filled($data['branch_id'] ?? null) ? [(int) $data['branch_id']] : app(CurrentStaff::class)->get()->limitedToBranchIds(),
            )));
    }

    /**
     * @return array<int, string>
     */
    public static function periods(): array
    {
        $periods = [];

        foreach (GymInsights::MONTHS as $months) {
            $periods[$months] = __('Last :count months', ['count' => $months]);
        }

        return $periods;
    }
}
