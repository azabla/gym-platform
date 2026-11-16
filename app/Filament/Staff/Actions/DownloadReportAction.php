<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Reports\Csv;
use App\Reports\Report;
use App\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download a report as a CSV file, for a period of the gym's days (today
 * unless chosen, at most a year) at one of the staff member's branches or all
 * of theirs, as the API's reports do. The export ability on the model's
 * policy decides who sees it. See ADR 0024.
 */
class DownloadReportAction extends Action
{
    /** @var class-string */
    protected string $reportedModel;

    /** @var Closure(ReportPeriod, array<string, mixed>): Report */
    protected Closure $makeReport;

    protected bool $coversAPeriod = true;

    public static function getDefaultName(): ?string
    {
        return 'downloadReport';
    }

    /**
     * @param  class-string  $model
     * @param  Closure(ReportPeriod, array<string, mixed>): Report  $makeReport
     */
    public function report(string $model, Closure $makeReport): static
    {
        $this->reportedModel = $model;
        $this->makeReport = $makeReport;

        return $this;
    }

    /**
     * For a report of how things are today, such as the members.
     */
    public function withoutAPeriod(): static
    {
        $this->coversAPeriod = false;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Download CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading('Download a CSV file')
            ->modalSubmitActionLabel('Download')
            ->visible(fn (): bool => Gate::allows('export', $this->reportedModel))
            ->schema(fn (): array => [
                ...($this->coversAPeriod ? [
                    DatePicker::make('from')->required()->default(fn (): string => self::today()->toDateString()),
                    DatePicker::make('to')
                        ->required()
                        ->default(fn (): string => self::today()->toDateString())
                        ->afterOrEqual('from')
                        ->rules(fn (Get $get): array => [self::atMostAYearAfter($get('from'))]),
                ] : []),
                Select::make('branch_id')
                    ->label('Branch')
                    ->placeholder('All my branches')
                    ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all()),
            ])
            ->action(fn (array $data): StreamedResponse => Csv::download(($this->makeReport)($this->periodIn($data), $data)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function periodIn(array $data): ReportPeriod
    {
        $today = self::today();
        $branchIds = filled($data['branch_id'] ?? null)
            ? [(int) $data['branch_id']]
            : app(CurrentStaff::class)->get()->limitedToBranchIds();

        return new ReportPeriod(
            app(CurrentOrganization::class)->get(),
            filled($data['from'] ?? null) ? CarbonImmutable::parse((string) $data['from'])->startOfDay() : $today,
            filled($data['to'] ?? null) ? CarbonImmutable::parse((string) $data['to'])->startOfDay() : $today,
            $branchIds,
        );
    }

    private static function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }

    private static function atMostAYearAfter(mixed $from): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($from): void {
            if (! is_string($from) || $from === '' || ! is_string($value)) {
                return;
            }

            if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($value)) >= 366) {
                $fail('The period can be at most a year long.');
            }
        };
    }
}
