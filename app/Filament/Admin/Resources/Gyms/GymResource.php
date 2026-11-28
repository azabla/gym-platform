<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Gyms;

use App\Dashboards\GymFigures;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\GymSuspension;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Onboarding\NewGym;
use App\Domains\PlatformBilling\Enums\GymBillStatus;
use App\Domains\PlatformBilling\GymBills;
use App\Domains\PlatformBilling\Models\GymSubscription;
use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Domains\Shared\Money\Money;
use App\Filament\Admin\Resources\Gyms\Pages\CreateGym;
use App\Filament\Admin\Resources\Gyms\Pages\EditGym;
use App\Filament\Admin\Resources\Gyms\Pages\ListGyms;
use App\Filament\Admin\Resources\Gyms\Pages\ViewGym;
use App\Filament\Staff\Support\Tables;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Every gym on the platform, for platform admins: who owns it, its branches
 * and how it is doing, in counts and totals only, never its members' names
 * or payments. Being able to open the admin panel is the authorization.
 * See ADR 0042.
 *
 * @phpstan-import-type Figures from GymFigures
 */
class GymResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $modelLabel = 'gym';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static bool $shouldSkipAuthorization = true;

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedBuildingStorefront, 'No gyms yet', 'Open the first gym with New gym.')
            ->defaultSort('name')
            ->searchPlaceholder('Gym name or slug')
            ->columns([
                TextColumn::make('name')
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Organization $record): string => '/'.$record->slug)
                    ->searchable(['name', 'slug'])
                    ->sortable(),
                TextColumn::make('status')
                    ->state(fn (Organization $record): string => $record->isSuspended() ? 'Paused' : 'Active')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Paused' ? 'danger' : 'success')
                    ->icon(fn (string $state): Heroicon => $state === 'Paused' ? Heroicon::OutlinedPauseCircle : Heroicon::OutlinedCheckCircle),
                TextColumn::make('bill')
                    ->state(fn (Organization $record, HasTable $livewire): string => self::billStatusesOf($livewire)[(int) $record->getKey()]->label())
                    ->badge()
                    ->color(fn (Organization $record, HasTable $livewire): string => GymBilling::STATUS_COLORS[self::billStatusesOf($livewire)[(int) $record->getKey()]->value]),
                Tables::money('owes', fn (Organization $record, HasTable $livewire): Money => self::owedBy($livewire)[(int) $record->getKey()] ?? Money::zero(), 'Owes'),
                TextColumn::make('owner')
                    ->state(fn (Organization $record, HasTable $livewire): string => self::ownersOf($livewire)[(int) $record->getKey()] ?? '—')
                    ->color('gray'),
                TextColumn::make('branches')
                    ->state(fn (Organization $record, HasTable $livewire): int => self::branchCountsOf($livewire)[(int) $record->getKey()] ?? 0)
                    ->alignEnd(),
                self::figure('members', 'Members'),
                self::figure('valid_memberships', 'Valid memberships'),
                self::figure('staff', 'Staff')->toggleable(isToggledHiddenByDefault: true),
                self::figure('check_ins_last_7_days', 'Check-ins, last 7 days'),
                Tables::money('taken_this_month', fn (Organization $record, HasTable $livewire): Money => self::figuresOf($livewire)[(int) $record->getKey()]['taken_this_month'], 'Taken this month'),
                TextColumn::make('last_activity')
                    ->state(fn (Organization $record, HasTable $livewire): ?CarbonImmutable => self::figuresOf($livewire)[(int) $record->getKey()]['last_activity'])
                    ->since()
                    ->placeholder('Nothing yet')
                    ->color('gray'),
                TextColumn::make('created_at')->label('Opened')->date('j M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->native(false)
                    ->options(['active' => 'Active', 'paused' => 'Paused'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'active' => $query->whereNull('suspended_at'),
                        'paused' => $query->whereNotNull('suspended_at'),
                        default => $query,
                    }),
                SelectFilter::make('bill')
                    ->native(false)
                    ->options(GymBilling::statusOptions())
                    ->query(fn (Builder $query, array $data): Builder => ($status = GymBillStatus::tryFrom((string) ($data['value'] ?? ''))) === null
                        ? $query
                        : app(GymBills::class)->whereStatus($query, $status)),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * Opening a gym, or changing it. The rules are NewGym's, the same as
     * gyms:create's; the slug is set once, and the branch and owner only
     * when the gym is opened (the owner adds the rest in the staff panel).
     */
    public static function form(Schema $schema): Schema
    {
        $rules = NewGym::rules();

        return $schema->components([
            Section::make('The gym')
                ->description('Its name, and its address in the staff panel and the apps.')
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextInput::make('name')
                        ->placeholder('Bole Fitness')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation): void {
                            if ($operation === 'create' && blank($get('slug'))) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                    TextInput::make('slug')
                        ->label('Address')
                        ->prefix('/staff/')
                        ->placeholder('bole-fitness')
                        ->helperText(fn (string $operation): string => $operation === 'create'
                            ? 'Small letters, digits and hyphens. It can\'t be changed later: links, the apps and printed QR codes use it.'
                            : 'Set when the gym was opened; links, the apps and printed QR codes use it.')
                        ->rules($rules['slug'])
                        ->required()
                        ->disabledOn('edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                    Select::make('timezone')
                        ->label('Time zone')
                        ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                        ->default('Africa/Addis_Ababa')
                        ->searchable()
                        ->native(false)
                        ->required()
                        ->helperText('"Today", opening hours and reminders follow it.'),
                    Select::make('message_language')
                        ->label('Members\' message language')
                        ->options(['en' => 'English', 'am' => 'Amharic (አማርኛ)'])
                        ->default('en')
                        ->native(false)
                        ->required()
                        ->helperText('The owner can change it later in Gym settings.'),
                ]),
            Section::make('First branch and owner')
                ->description('The owner gets an SMS with a link to set their password, then adds the rest of the staff.')
                ->icon(Heroicon::OutlinedUserCircle)
                ->columns(['default' => 1, 'md' => 3])
                ->visibleOn('create')
                ->schema([
                    TextInput::make('branch')
                        ->label('First branch')
                        ->default('Main Branch')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('owner_name')
                        ->label('Owner\'s name')
                        ->placeholder('Meron Assefa')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('owner_phone')
                        ->label('Owner\'s phone')
                        ->placeholder('0911 22 33 44')
                        ->tel()
                        ->rules($rules['owner_phone'])
                        ->required()
                        ->helperText('They sign in with it.'),
                ]),
            Section::make('Billing')
                ->description('Optional: a gym without a plan isn\'t billed until you set it up on its page.')
                ->icon(Heroicon::OutlinedBanknotes)
                ->columns(['default' => 1, 'md' => 3])
                ->visibleOn('create')
                ->schema([
                    Select::make('billing_plan')
                        ->label('Plan')
                        ->options(fn (): array => PlatformPlan::query()->offered()->orderBy('monthly_price_minor')->get()
                            ->mapWithKeys(fn (PlatformPlan $plan): array => [(int) $plan->getKey() => $plan->name.' · '.$plan->monthly_price->format().' a month'])
                            ->all())
                        ->placeholder('Not billed for now')
                        ->native(false)
                        ->live(),
                    TextInput::make('trial_days')
                        ->label('Free trial')
                        ->integer()
                        ->minValue(0)
                        ->maxValue(GymSubscription::MOST_TRIAL_DAYS)
                        ->default(GymSubscription::DEFAULT_TRIAL_DAYS)
                        ->suffix('days')
                        ->helperText('0 to bill from today.')
                        ->visible(fn (Get $get): bool => filled($get('billing_plan')))
                        ->required(fn (Get $get): bool => filled($get('billing_plan'))),
                    Select::make('months_per_invoice')
                        ->label('Each invoice covers')
                        ->options([1 => '1 month', 3 => '3 months', 6 => '6 months', 12 => '12 months'])
                        ->default(1)
                        ->native(false)
                        ->visible(fn (Get $get): bool => filled($get('billing_plan')))
                        ->required(fn (Get $get): bool => filled($get('billing_plan'))),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('How it is doing')
                ->description('Counts and totals only: the members\' names and payments stay with the gym.')
                ->icon(Heroicon::OutlinedChartBar)
                ->columns(['default' => 2, 'md' => 3])
                ->schema([
                    TextEntry::make('members')->state(fn (Organization $record): int => self::figuresOfOne($record)['members']),
                    TextEntry::make('valid_memberships')->state(fn (Organization $record): int => self::figuresOfOne($record)['valid_memberships']),
                    TextEntry::make('staff')->state(fn (Organization $record): int => self::figuresOfOne($record)['staff']),
                    TextEntry::make('check_ins_last_7_days')->label('Check-ins, last 7 days')->state(fn (Organization $record): int => self::figuresOfOne($record)['check_ins_last_7_days']),
                    TextEntry::make('taken_this_month')->state(fn (Organization $record): string => self::figuresOfOne($record)['taken_this_month']->format()),
                    TextEntry::make('last_activity')->state(fn (Organization $record): ?CarbonImmutable => self::figuresOfOne($record)['last_activity'])->since()->placeholder('Nothing yet'),
                ]),
            GymBilling::section(),
            Section::make('The gym')
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('slug')->label('Address')->formatStateUsing(fn (string $state): string => '/staff/'.$state)->fontFamily('mono'),
                    TextEntry::make('timezone')->label('Time zone'),
                    TextEntry::make('message_language')->label('Members\' message language')->formatStateUsing(fn (Organization $record): string => $record->messageLanguage()->label()),
                    TextEntry::make('created_at')->label('Opened')->date('j M Y'),
                ]),
            Section::make('Paused')
                ->description('Its staff, members\' app, door and messages are stopped until it is resumed.')
                ->icon(Heroicon::OutlinedPauseCircle)
                ->iconColor('danger')
                ->visible(fn (Organization $record): bool => $record->isSuspended())
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('paused_since')
                        ->label('Since')
                        ->state(fn (Organization $record): ?CarbonImmutable => $record->suspensions()->first()?->suspended_at)
                        ->dateTime('j M Y, H:i'),
                    TextEntry::make('paused_by')
                        ->label('By')
                        ->state(fn (Organization $record): ?string => $record->suspensions()->with('suspendedBy')->first()?->suspendedBy?->name),
                    TextEntry::make('paused_why')
                        ->label('Why')
                        ->state(fn (Organization $record): ?string => $record->suspensions()->first()?->reason),
                ]),
            Section::make('Owners and branches')
                ->icon(Heroicon::OutlinedUsers)
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextEntry::make('owners')
                        ->state(fn (Organization $record): array => self::ownerList($record))
                        ->listWithLineBreaks()
                        ->placeholder('No owner'),
                    TextEntry::make('branch_names')
                        ->label('Branches')
                        ->state(fn (Organization $record): array => Branch::query()->acrossOrganizations()->where('organization_id', $record->getKey())->orderBy('name')->pluck('name')->map(fn (mixed $name): string => (string) $name)->all())
                        ->listWithLineBreaks(),
                ]),
            Section::make('Pause history')
                ->icon(Heroicon::OutlinedClock)
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextEntry::make('pause_history')
                        ->hiddenLabel()
                        ->state(fn (Organization $record): array => self::pauseHistory($record))
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('Never paused'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGyms::route('/'),
            'create' => CreateGym::route('/new'),
            'view' => ViewGym::route('/{record}'),
            'edit' => EditGym::route('/{record}/edit'),
        ];
    }

    /**
     * Each pause, newest first: "Paused 1 Nov 2026 by Selam: hasn't paid · Resumed 3 Nov 2026 by Abel: paid"
     *
     * @return list<string>
     */
    private static function pauseHistory(Organization $gym): array
    {
        return array_values($gym->suspensions()->with(['suspendedBy', 'resumedBy'])->get()->map(function (GymSuspension $pause): string {
            $line = 'Paused '.$pause->suspended_at->format('j M Y').' by '.$pause->suspendedBy->name.': '.$pause->reason;

            if ($pause->resumed_at !== null) {
                $line .= ' · Resumed '.$pause->resumed_at->format('j M Y').' by '.$pause->resumedBy->name.($pause->resume_note === null ? '' : ': '.$pause->resume_note);
            }

            return $line;
        })->all());
    }

    private static function figure(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->state(fn (Organization $record, HasTable $livewire): int => self::figuresOf($livewire)[(int) $record->getKey()][$name])
            ->numeric()
            ->alignEnd();
    }

    /**
     * The figures of the gyms on the page, looked up once for the page.
     *
     * @return array<int, Figures>
     */
    private static function figuresOf(HasTable $livewire): array
    {
        return once(fn (): array => app(GymFigures::class)->of(self::gymsOnThePage($livewire)));
    }

    /**
     * @return Figures
     */
    private static function figuresOfOne(Organization $gym): array
    {
        return once(fn (): array => app(GymFigures::class)->of([$gym])[(int) $gym->getKey()]);
    }

    /**
     * Where each gym on the page stands with its bill, looked up once for the page.
     *
     * @return array<int, GymBillStatus>
     */
    private static function billStatusesOf(HasTable $livewire): array
    {
        return once(fn (): array => app(GymBills::class)->statusesOf(self::gymsOnThePage($livewire)));
    }

    /**
     * What each gym on the page owes the platform, looked up once for the page.
     *
     * @return array<int, Money>
     */
    private static function owedBy(HasTable $livewire): array
    {
        return once(fn (): array => app(GymBills::class)->owedBy(self::gymsOnThePage($livewire)));
    }

    /**
     * Each gym's owners, "Name (+251…)", by gym id, for the page.
     *
     * @return array<int, string>
     */
    private static function ownersOf(HasTable $livewire): array
    {
        return once(function () use ($livewire): array {
            $owners = [];

            foreach (self::owners(self::idsOnThePage($livewire)) as $owner) {
                $owners[(int) $owner->organization_id][] = self::nameAndPhone($owner);
            }

            return array_map(fn (array $names): string => implode(', ', $names), $owners);
        });
    }

    /**
     * @return array<int, int>
     */
    private static function branchCountsOf(HasTable $livewire): array
    {
        return once(function () use ($livewire): array {
            $counts = [];
            $rows = Branch::query()->acrossOrganizations()
                ->whereIn('organization_id', self::idsOnThePage($livewire))
                ->groupBy('organization_id')
                ->selectRaw('organization_id, COUNT(*) AS branches')
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $counts[(int) $row->organization_id] = (int) $row->branches;
            }

            return $counts;
        });
    }

    /**
     * @return list<string>
     */
    private static function ownerList(Organization $gym): array
    {
        return array_map(fn (Staff $owner): string => self::nameAndPhone($owner), self::owners([(int) $gym->getKey()]));
    }

    /**
     * @param  list<int>  $gymIds
     * @return list<Staff>
     */
    private static function owners(array $gymIds): array
    {
        return array_values(Staff::query()->acrossOrganizations()
            ->whereIn('organization_id', $gymIds)
            ->where('role', StaffRole::Owner)
            ->with('user')
            ->orderBy('id')
            ->get()
            ->all());
    }

    private static function nameAndPhone(Staff $staff): string
    {
        return $staff->user->name.' ('.($staff->user->phone ?? $staff->user->email).')';
    }

    /**
     * @return list<int>
     */
    private static function idsOnThePage(HasTable $livewire): array
    {
        return array_map(fn (Organization $gym): int => (int) $gym->getKey(), self::gymsOnThePage($livewire));
    }

    /**
     * @return list<Organization>
     */
    private static function gymsOnThePage(HasTable $livewire): array
    {
        return array_values(array_filter(Tables::pageRecords($livewire), fn (Model $record): bool => $record instanceof Organization));
    }
}
