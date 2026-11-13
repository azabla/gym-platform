<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Announcements;

use App\Domains\Announcements\Enums\AnnouncementStatus;
use App\Domains\Announcements\Exceptions\AnnouncementRefused;
use App\Domains\Announcements\Models\Announcement;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Actions\Refusals;
use App\Filament\Staff\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Staff\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Staff\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Staff\Resources\Concerns\ShowsGymTime;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Announcements from the gym to its members, to every branch or one, now
 * or at a time in the gym's time zone, kept in UTC. Owners and managers
 * post them; staff limited to some branches speak only to one of theirs.
 * One that hasn't gone out can be changed or cancelled. See ADR 0015 and
 * ADR 0022.
 */
class AnnouncementResource extends Resource
{
    use ShowsGymTime;

    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Announcement')
                    ->schema([
                        TextInput::make('title')->required()->maxLength(80),
                        Textarea::make('body')->required()->maxLength(1000)->rows(4),
                        Select::make('branch_id')
                            ->label('To')
                            ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->mapWithKeys(
                                fn (Branch $branch): array => [$branch->id => "Members of {$branch->name}"],
                            )->all())
                            ->placeholder('Every member of the gym')
                            ->required(fn (): bool => ! app(CurrentStaff::class)->get()->works_at_all_branches),
                        Toggle::make('include_recently_expired')
                            ->label('Also members whose membership ended in the last '.Announcement::RECENTLY_EXPIRED_DAYS.' days'),
                        Toggle::make('send_now')
                            ->label('Send now')
                            ->default(true)
                            ->live()
                            ->visibleOn('create')
                            ->dehydrated(),
                        DateTimePicker::make('send_at')
                            ->label('Send at')
                            ->seconds(false)
                            ->timezone(self::gymTimezone())
                            ->helperText('In the gym\'s time zone.')
                            ->required(fn (Get $get, string $operation): bool => $operation === 'edit' || ! $get('send_now'))
                            ->visible(fn (Get $get, string $operation): bool => $operation === 'edit' || ! $get('send_now')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('send_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('branch'))
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('audience')
                    ->label('To')
                    ->state(fn (Announcement $record): string => $record->branch === null ? 'Whole gym' : $record->branch->name),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (Announcement $record): string => $record->status()->value)
                    ->color(fn (string $state): string => match (AnnouncementStatus::from($state)) {
                        AnnouncementStatus::Scheduled => 'warning',
                        AnnouncementStatus::Sent => 'success',
                        AnnouncementStatus::Cancelled => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('send_at')->label('Goes out')->dateTime()->timezone(self::gymTimezone())->sortable(),
                TextColumn::make('recipients_count')->label('Reached')->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make(),
                self::cancelAction(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }

    /**
     * Only an announcement that hasn't gone out can be changed, by staff the
     * policy lets change it.
     */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof Announcement && $record->isScheduled() && parent::canEdit($record);
    }

    /**
     * The branch chosen on the form, or null for every branch.
     *
     * @param  array<string, mixed>  $data
     */
    public static function branchIn(array $data): ?Branch
    {
        return filled($data['branch_id'] ?? null) ? Branch::query()->findOrFail((int) $data['branch_id']) : null;
    }

    /**
     * The time chosen on the form, already in UTC, or null for now.
     *
     * @param  array<string, mixed>  $data
     */
    public static function sendAtIn(array $data): ?CarbonImmutable
    {
        return filled($data['send_at'] ?? null) ? CarbonImmutable::parse((string) $data['send_at'], 'UTC') : null;
    }

    /**
     * Show a refusal on the form's field it belongs to.
     */
    public static function refusalOnTheForm(AnnouncementRefused $refusal): ValidationException
    {
        return ValidationException::withMessages(['data.'.$refusal->field => $refusal->getMessage()]);
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Cancel this announcement?')
            ->modalDescription('It won\'t go out.')
            ->visible(fn (Announcement $record): bool => $record->isScheduled() && Gate::allows('update', $record))
            ->action(function (Announcement $record, Action $action): void {
                if (! $record->cancel()) {
                    Refusals::halt($action, AnnouncementRefused::alreadyGoneOut());
                }

                Notification::make()->success()->title('Announcement cancelled.')->send();
            });
    }
}
