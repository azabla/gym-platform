<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\MemberImports;

use App\Domains\Imports\Enums\MemberImportStatus;
use App\Domains\Imports\Models\MemberImport;
use App\Filament\Staff\Resources\Concerns\ShowsGymTime;
use App\Filament\Staff\Resources\MemberImports\Pages\ListMemberImports;
use App\Filament\Staff\Resources\MemberImports\Pages\ViewMemberImport;
use App\Filament\Staff\Resources\MemberImports\RelationManagers\RowsRelationManager;
use App\Filament\Staff\Support\Badges;
use App\Filament\Staff\Support\Dates;
use App\Filament\Staff\Support\Tables;
use App\Filament\Staff\Support\TranslatesItsLabels;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Files of members the gym had before joining, brought in by its owners
 * and managers: each checked first, then imported once confirmed. Reached
 * from the members list. See ADR 0044.
 */
class MemberImportResource extends Resource
{
    use ShowsGymTime;
    use TranslatesItsLabels;

    protected static ?string $model = MemberImport::class;

    protected static ?string $modelLabel = 'member import';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static bool $shouldRegisterNavigation = false;

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedArrowUpTray, __('No imports yet'), __('Bring in the members your gym had before, from a CSV file: download the template, fill it in and upload it.'))
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('uploadedBy.user'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Uploaded'))
                    ->formatStateUsing(Dates::asDateTime(self::gymTimezone()))
                    ->sortable(),
                TextColumn::make('file_name')->label(__('File'))->weight(FontWeight::Medium)->searchable(),
                Tables::badge('status', __('Status')),
                TextColumn::make('rows_total')->label(__('Rows'))->numeric()->alignEnd(),
                TextColumn::make('added_count')->label(__('Added'))->numeric()->alignEnd()->color('success'),
                TextColumn::make('not_imported')
                    ->label(__('Not imported'))
                    ->state(fn (MemberImport $record): int => $record->error_count + $record->duplicate_count + $record->failed_count)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('uploadedBy.user.name')->label(__('By'))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->native(false)->options(Badges::options(MemberImportStatus::class)),
                Tables::dateRange('uploaded', 'created_at', __('Uploaded'), isMoment: true),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The file'))
                ->description(__('Look over the rows below. Only rows marked ready are imported; fix the others in your file and upload it again.'))
                ->icon(Heroicon::OutlinedDocumentText)
                ->columns(['default' => 2, 'md' => 4])
                ->schema([
                    TextEntry::make('file_name')->label(__('File')),
                    TextEntry::make('status')->label(__('Status'))->badge()
                        ->formatStateUsing(fn (MemberImportStatus $state): string => Badges::label($state))
                        ->color(fn (MemberImportStatus $state): string => Badges::color($state))
                        ->icon(fn (MemberImportStatus $state): ?Heroicon => Badges::icon($state)),
                    TextEntry::make('uploadedBy.user.name')->label(__('By')),
                    TextEntry::make('created_at')->label(__('Uploaded'))->formatStateUsing(Dates::asDateTime(self::gymTimezone())),
                    TextEntry::make('ready_count')->label(__('Ready'))->numeric()->color('info'),
                    TextEntry::make('added_count')->label(__('Added'))->numeric()->color('success'),
                    TextEntry::make('error_count')
                        ->label(__('Errors'))
                        ->state(fn (MemberImport $record): int => $record->error_count + $record->failed_count)
                        ->numeric()
                        ->color('danger'),
                    TextEntry::make('duplicate_count')->label(__('Already members'))->numeric(),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RowsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMemberImports::route('/'),
            'view' => ViewMemberImport::route('/{record}'),
        ];
    }
}
