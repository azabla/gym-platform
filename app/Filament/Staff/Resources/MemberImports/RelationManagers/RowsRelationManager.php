<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\MemberImports\RelationManagers;

use App\Domains\Imports\Enums\MemberImportRowOutcome;
use App\Domains\Imports\Enums\MemberImportStatus;
use App\Domains\Imports\Models\MemberImport;
use App\Domains\Imports\Models\MemberImportRow;
use App\Filament\Staff\Support\Badges;
use App\Filament\Staff\Support\Dates;
use App\Filament\Staff\Support\Tables;
use Carbon\CarbonImmutable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Each row of the file, as it was, with what checking or importing it gave
 * and why when it wasn't imported. Dates show as read, Ethiopian and
 * Gregorian, so a date the spreadsheet changed is seen before importing.
 * Refreshes itself while the file is being imported. See ADR 0044.
 */
class RowsRelationManager extends RelationManager
{
    protected static string $relationship = 'rows';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Rows');
    }

    public function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedTableCells, __('No rows here'), __('Change the filter to see the other rows.'))
            ->defaultSort('line_number')
            ->poll(fn (): ?string => $this->import()->status === MemberImportStatus::Importing ? '3s' : null)
            ->description(fn (): string => $this->progress())
            ->columns([
                TextColumn::make('line_number')->label(__('Line'))->numeric()->sortable(),
                TextColumn::make('cells.full_name')->label(__('Full name'))->searchable(query: fn ($query, string $search) => $query->where('cells->full_name', 'ilike', "%{$search}%")),
                TextColumn::make('cells.phone')->label(__('Phone'))->fontFamily('mono'),
                TextColumn::make('cells.home_branch')->label(__('Home branch'))->toggleable(),
                TextColumn::make('membership')
                    ->label(__('Membership'))
                    ->state(fn (MemberImportRow $record): ?string => self::membershipOf($record))
                    ->placeholder(__('None'))
                    ->wrap(),
                Tables::badge('outcome', __('Result')),
                TextColumn::make('reason')
                    ->label(__('Why not'))
                    ->state(fn (MemberImportRow $record): ?string => $record->reason())
                    ->color('danger')
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('outcome')->label(__('Result'))->native(false)->options(Badges::options(MemberImportRowOutcome::class)),
            ]);
    }

    /**
     * "Monthly, from ኅዳር 1, 2019 (01/11/2026), paid 1500", as the row reads.
     */
    private static function membershipOf(MemberImportRow $row): ?string
    {
        $plan = $row->cells['plan'] ?? '';

        if ($plan === '') {
            return null;
        }

        $parts = [$plan];
        $startsOn = self::dateOf($row->cells['starts_on'] ?? '');

        if ($startsOn !== null) {
            $parts[] = __('from :date', ['date' => Dates::short($startsOn)]);
        }

        if (($row->cells['amount_paid'] ?? '') !== '') {
            $parts[] = __('paid :amount', ['amount' => $row->cells['amount_paid']]);
        }

        return implode(', ', $parts);
    }

    private static function dateOf(string $typed): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', trim($typed), $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return CarbonImmutable::create((int) $parts[1], (int) $parts[2], (int) $parts[3]);
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', trim($typed), $parts) === 1 && checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3])) {
            return CarbonImmutable::create((int) $parts[3], (int) $parts[2], (int) $parts[1]);
        }

        return null;
    }

    private function progress(): string
    {
        $import = $this->import()->fresh() ?? $this->import();

        return $import->status === MemberImportStatus::Importing
            ? __('Importing… :added added, :left to go.', ['added' => $import->added_count, 'left' => $import->ready_count])
            : __(':ready ready, :added added, :errors not imported, :duplicates already members.', [
                'ready' => $import->ready_count,
                'added' => $import->added_count,
                'errors' => $import->error_count + $import->failed_count,
                'duplicates' => $import->duplicate_count,
            ]);
    }

    private function import(): MemberImport
    {
        /** @var MemberImport */
        return $this->getOwnerRecord();
    }
}
