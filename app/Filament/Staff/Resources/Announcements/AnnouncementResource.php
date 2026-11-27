<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Announcements;

use App\Domains\Announcements\Enums\AnnouncementStatus;
use App\Domains\Announcements\Exceptions\AnnouncementRefused;
use App\Domains\Announcements\Models\Announcement;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Actions\Refusals;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Staff\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Staff\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Staff\Resources\Concerns\ShowsGymTime;
use App\Filament\Staff\Support\Badges;
use App\Filament\Staff\Support\Dates;
use App\Filament\Staff\Support\Tables;
use App\Filament\Staff\Support\TranslatesItsLabels;
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
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Announcements from the gym to its members, to every branch or one, now
 * or at a time in the gym's time zone, kept in UTC. Owners and managers
 * post them; staff limited to some branches speak only to one of theirs.
 * One that hasn't gone out can be changed or cancelled. See ADR 0015 and
 * ADR 0023.
 */
class AnnouncementResource extends Resource
{
    use ShowsGymTime;
    use TranslatesItsLabels;

    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::FrontDesk;

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Message'))
                    ->description(__('Members see it in the app\'s inbox, and as a push on their phone if they allow them.'))
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->schema([
                        TextInput::make('title')->label(__('Title'))
                            ->placeholder(__('Closed on Sunday'))
                            ->required()
                            ->maxLength(80)
                            ->hint(__('Up to 80 characters')),
                        Textarea::make('body')
                            ->label(__('Message'))
                            ->placeholder(__('Both branches are closed this Sunday for the holiday. See you on Monday!'))
                            ->required()
                            ->maxLength(1000)
                            ->rows(5)
                            ->autosize()
                            ->hint(__('Up to 1,000 characters')),
                    ]),
                Section::make(__('Who and when'))
                    ->icon(Heroicon::OutlinedUserGroup)
                    ->columns(2)
                    ->schema([
                        Select::make('branch_id')
                            ->label(__('To'))
                            ->native(false)
                            ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->mapWithKeys(
                                fn (Branch $branch): array => [$branch->id => __('Members of :branch', ['branch' => $branch->name])],
                            )->all())
                            ->placeholder(__('Every member of the gym'))
                            ->helperText(fn (): ?string => app(CurrentStaff::class)->get()->works_at_all_branches ? null : __('You speak to the members of one of your branches.'))
                            ->required(fn (): bool => ! app(CurrentStaff::class)->get()->works_at_all_branches),
                        Toggle::make('include_recently_expired')
                            ->label(__('Also recent members'))
                            ->helperText(__('Members whose membership ended in the last :count days, to win them back.', ['count' => Announcement::RECENTLY_EXPIRED_DAYS]))
                            ->inline(false),
                        Toggle::make('send_now')
                            ->label(__('Send now'))
                            ->helperText(__('Turn off to choose a time.'))
                            ->default(true)
                            ->live()
                            ->inline(false)
                            ->visibleOn('create')
                            ->dehydrated(),
                        DateTimePicker::make('send_at')
                            ->label(__('Send at'))
                            ->native(false)
                            ->seconds(false)
                            ->displayFormat('j M Y, H:i')
                            ->timezone(self::gymTimezone())
                            ->prefixIcon(Heroicon::OutlinedClock)
                            ->helperText(__('In the gym\'s time zone. It goes out within a minute of this time.'))
                            ->required(fn (Get $get, string $operation): bool => $operation === 'edit' || ! $get('send_now'))
                            ->visible(fn (Get $get, string $operation): bool => $operation === 'edit' || ! $get('send_now')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedMegaphone, __('No announcements yet'), __('Tell members about closures, classes or offers, now or at a time you choose.'))
            ->defaultSort('send_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['branch', 'postedBy.user']))
            ->searchPlaceholder(__('Title'))
            ->columns([
                TextColumn::make('title')->label(__('Title'))
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Announcement $record): string => str($record->body)->limit(70)->toString())
                    ->searchable()
                    ->wrap(),
                TextColumn::make('audience')
                    ->label(__('To'))
                    ->badge()
                    ->color(fn (Announcement $record): string => $record->branch === null ? 'primary' : 'gray')
                    ->state(fn (Announcement $record): string => $record->branch === null ? __('Whole gym') : $record->branch->name),
                Tables::badge('status')
                    ->state(fn (Announcement $record): AnnouncementStatus => $record->status()),
                TextColumn::make('send_at')
                    ->label(__('Goes out'))
                    ->formatStateUsing(Dates::asDateTime(self::gymTimezone()))
                    ->description(fn (Announcement $record): string => $record->send_at->diffForHumans())
                    ->sortable(),
                TextColumn::make('recipients_count')
                    ->label(__('Reached'))
                    ->numeric()
                    ->alignEnd()
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : number_format($state).' members')
                    ->placeholder('—'),
                TextColumn::make('postedBy.user.name')->label(__('Posted by'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))
                    ->native(false)
                    ->options(Badges::options(AnnouncementStatus::class))
                    ->query(fn (Builder $query, array $data): Builder => match (AnnouncementStatus::tryFrom((string) ($data['value'] ?? ''))) {
                        AnnouncementStatus::Scheduled => $query->whereNull('sent_at')->whereNull('cancelled_at'),
                        AnnouncementStatus::Sent => $query->whereNotNull('sent_at'),
                        AnnouncementStatus::Cancelled => $query->whereNotNull('cancelled_at'),
                        null => $query,
                    }),
                SelectFilter::make('to')
                    ->label(__('To'))
                    ->native(false)
                    ->options(fn (): array => ['gym' => __('Whole gym'), ...app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all()])
                    ->query(fn (Builder $query, array $data): Builder => match (true) {
                        blank($data['value'] ?? null) => $query,
                        $data['value'] === 'gym' => $query->whereNull('branch_id'),
                        default => $query->where('branch_id', (int) $data['value']),
                    }),
                Tables::dateRange('goes_out', 'send_at', __('Goes out'), isMoment: true),
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
            ->label(__('Cancel'))
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Cancel this announcement?'))
            ->modalDescription(__('It won\'t go out.'))
            ->visible(fn (Announcement $record): bool => $record->isScheduled() && Gate::allows('update', $record))
            ->action(function (Announcement $record, Action $action): void {
                if (! $record->cancel()) {
                    Refusals::halt($action, AnnouncementRefused::alreadyGoneOut());
                }

                Notification::make()->success()->title(__('Announcement cancelled.'))->send();
            });
    }
}
