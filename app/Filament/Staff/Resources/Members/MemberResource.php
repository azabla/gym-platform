<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Members;

use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Enums\Gender;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\MembershipStatus;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Memberships\Models\MemberDiscount;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use App\Domains\Shared\PhoneNumber\Exceptions\InvalidPhoneNumber;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use App\Filament\Staff\Actions\CheckInAction;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\Concerns\LimitsToTheStaffMembersBranches;
use App\Filament\Staff\Resources\Concerns\ShowsGymTime;
use App\Filament\Staff\Resources\Members\Pages\CreateMember;
use App\Filament\Staff\Resources\Members\Pages\EditMember;
use App\Filament\Staff\Resources\Members\Pages\ListMembers;
use App\Filament\Staff\Resources\Members\Pages\ViewMember;
use App\Filament\Staff\Support\ActivityHistory;
use App\Filament\Staff\Support\Badges;
use App\Filament\Staff\Support\Dates;
use App\Filament\Staff\Support\Languages;
use App\Filament\Staff\Support\Photos;
use App\Filament\Staff\Support\Tables;
use App\Filament\Staff\Support\TranslatesItsLabels;
use App\Rules\ValidPhoneNumber;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use UnitEnum;

/**
 * The gym's members: registering, finding, editing and archiving them, with
 * the API's rules. Archived members are left out, as in the API. The list
 * shows each member's membership today, what they owe (to the roles that see
 * money) and their discount group, worked out once per page. See ADR 0017,
 * ADR 0018 and ADR 0033.
 */
class MemberResource extends Resource
{
    use LimitsToTheStaffMembersBranches;
    use ShowsGymTime;
    use TranslatesItsLabels;

    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::FrontDesk;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'full_name';

    /**
     * What the current page's members show beside their own details, keyed
     * by member id, worked out once for the page.
     *
     * @var array{key: string, memberships: array<int, ?Membership>, balances: array<int, Money>, groups: array<int, string>}|null
     */
    private static ?array $page = null;

    /**
     * The API's rules for a member (StoreMemberRequest): one member per phone
     * number in the gym, and a home branch the staff member works at.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Member'))
                    ->description(__('Who they are and where they train. They sign in to the members app with this phone number.'))
                    ->icon(Heroicon::OutlinedUser)
                    ->columns(2)
                    ->schema([
                        TextInput::make('full_name')
                            ->label(__('Full name'))
                            ->placeholder(__('Abebe Kebede'))
                            ->required()
                            ->maxLength(255)
                            ->autofocus(),
                        TextInput::make('phone')->label(__('Phone'))
                            ->tel()
                            ->placeholder('0911 23 45 67')
                            ->prefixIcon(Heroicon::OutlinedPhone)
                            ->helperText(__('One member per number in this gym. Any Ethiopian format works.'))
                            ->required()
                            ->rules(fn (?Member $record): array => [new ValidPhoneNumber, self::phoneNotTakenInTheGym($record)]),
                        Select::make('home_branch_id')
                            ->label(__('Home branch'))
                            ->required()
                            ->native(false)
                            ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all())
                            ->default(fn (): ?int => self::onlyBranchWorkedAt())
                            ->helperText(__('The plans sold to them are the ones on sale here.')),
                        TextInput::make('email')->label(__('Email'))
                            ->email()
                            ->placeholder(__('Optional'))
                            ->prefixIcon(Heroicon::OutlinedEnvelope)
                            ->maxLength(255),
                        Select::make('gender')->label(__('Gender'))
                            ->native(false)
                            ->placeholder(__('Not given'))
                            ->options(Badges::options(Gender::class)),
                        DatePicker::make('date_of_birth')->hint(Dates::ethiopianHint())->live()
                            ->label(__('Date of birth'))
                            ->native(false)
                            ->displayFormat('j M Y')
                            ->maxDate(fn (): string => self::today()->subDay()->toDateString())
                            ->beforeOrEqual('yesterday'),
                        Select::make('message_language')
                            ->label(__('Message language'))
                            ->native(false)
                            ->placeholder(fn (): string => Languages::gymDefault())
                            ->options(Languages::options())
                            ->prefixIcon(Heroicon::OutlinedLanguage)
                            ->helperText(__('Their texts come in this language. The members app changes it when they switch language.')),
                    ]),
                Section::make(__('Emergency contact'))
                    ->description(__('Who to call if something happens at the gym. Optional, but give both or neither.'))
                    ->icon(Heroicon::OutlinedLifebuoy)
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('emergency_contact_name')
                            ->label(__('Name'))
                            ->placeholder(__('Almaz Kebede'))
                            ->maxLength(255)
                            ->requiredWith('emergency_contact_phone'),
                        TextInput::make('emergency_contact_phone')
                            ->label(__('Phone'))
                            ->tel()
                            ->placeholder('0911 98 76 54')
                            ->prefixIcon(Heroicon::OutlinedPhone)
                            ->requiredWith('emergency_contact_name')
                            ->rules([new ValidPhoneNumber]),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('At a glance'))
                    ->icon(Heroicon::OutlinedSparkles)
                    ->schema([
                        ImageEntry::make('photo')
                            ->label(__('Photo'))
                            ->hiddenLabel()
                            ->state(fn (Member $record): string => Photos::urlFor($record))
                            ->circular()
                            ->imageSize(112),
                        Grid::make(['default' => 2, 'lg' => 4])->schema([
                            TextEntry::make('membership_today')
                                ->label(__('Membership today'))
                                ->state(fn (Member $record): string => self::membershipSummaryOf($record))
                                ->badge()
                                ->color(fn (Member $record): string => Badges::color(self::statusTodayOf($record)))
                                ->icon(fn (Member $record): ?Heroicon => Badges::icon(self::statusTodayOf($record))),
                            TextEntry::make('balance_due')
                                ->label(__('Balance due'))
                                ->state(fn (Member $record): string => self::balanceDueOf($record)->format())
                                ->weight(FontWeight::Bold)
                                ->color(fn (Member $record): string => self::balanceDueOf($record)->isPositive() ? 'danger' : 'success'),
                            TextEntry::make('discount_group')
                                ->label(__('Discount group'))
                                ->state(fn (Member $record): ?string => self::discountGroupOf($record))
                                ->badge()
                                ->color('info')
                                ->placeholder(__('None')),
                            TextEntry::make('last_visit')
                                ->label(__('Last visit'))
                                ->state(fn (Member $record): ?CarbonImmutable => self::lastVisitOf($record))
                                ->since()
                                ->timezone(self::gymTimezone())
                                ->placeholder(__('Never')),
                        ]),
                    ]),
                Section::make(__('Member'))
                    ->icon(Heroicon::OutlinedUser)
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('full_name')->label(__('Full name'))->weight(FontWeight::Bold),
                        TextEntry::make('phone')->label(__('Phone'))->copyable()->copyMessage(__('Copied'))->icon(Heroicon::OutlinedPhone),
                        TextEntry::make('email')->label(__('Email'))->placeholder(__('None'))->copyable()->icon(Heroicon::OutlinedEnvelope),
                        TextEntry::make('homeBranch.name')->label(__('Home branch'))->badge()->color('gray'),
                        TextEntry::make('gender')->label(__('Gender'))
                            ->formatStateUsing(fn (mixed $state): string => $state instanceof BackedEnum ? Badges::label($state) : (string) $state)
                            ->placeholder(__('Not given')),
                        TextEntry::make('date_of_birth')->label(__('Date of birth'))->formatStateUsing(Dates::asDate())->placeholder(__('Not given')),
                        TextEntry::make('created_at')->label(__('Registered'))->formatStateUsing(Dates::asDate(self::gymTimezone())),
                        TextEntry::make('message_language')
                            ->label(__('Message language'))
                            ->state(fn (Member $record): string => $record->message_language === null ? Languages::gymDefault() : Languages::label($record->message_language))
                            ->icon(Heroicon::OutlinedLanguage),
                    ]),
                Section::make(__('Emergency contact'))
                    ->icon(Heroicon::OutlinedLifebuoy)
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextEntry::make('emergency_contact_name')->label(__('Name'))->placeholder(__('None')),
                        TextEntry::make('emergency_contact_phone')->label(__('Phone'))->placeholder(__('None'))->copyable(),
                    ]),
                ActivityHistory::section(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedUsers, __('No members found'), __('Register a member, or search by part of a name or phone number to find one at another branch.'))
            ->recordTitleAttribute('full_name')
            ->defaultSort('full_name')
            ->modifyQueryUsing(self::listedMembers(...))
            ->splitSearchTerms(false)
            ->searchPlaceholder(__('Name or phone number'))
            ->columns([
                ImageColumn::make('photo')
                    ->label('')
                    ->state(fn (Member $record): string => Photos::urlFor($record))
                    ->circular()
                    ->imageSize(36),
                TextColumn::make('full_name')
                    ->label(__('Name'))
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Member $record): string => (string) $record->phone)
                    ->sortable()
                    ->searchable(query: self::searchMembers(...)),
                TextColumn::make('phone')->label(__('Phone'))
                    ->copyable()
                    ->copyMessage(__('Copied'))
                    ->icon(Heroicon::OutlinedPhone)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('homeBranch.name')->label(__('Home branch'))->badge()->color('gray'),
                TextColumn::make('membership_today')
                    ->label(__('Membership today'))
                    ->state(fn (Member $record, HasTable $livewire): string => self::membershipSummaryOf($record, $livewire))
                    ->badge()
                    ->color(fn (Member $record, HasTable $livewire): string => Badges::color(self::statusTodayOf($record, $livewire)))
                    ->icon(fn (Member $record, HasTable $livewire): ?Heroicon => Badges::icon(self::statusTodayOf($record, $livewire))),
                Tables::money('balance_due', fn (Member $record, HasTable $livewire): Money => self::pageFor($livewire)['balances'][$record->id] ?? Money::zero(), __('Owes'))
                    ->color(fn (Member $record, HasTable $livewire): string => (self::pageFor($livewire)['balances'][$record->id] ?? Money::zero())->isPositive() ? 'danger' : 'gray')
                    ->visible(fn (): bool => app(CurrentStaff::class)->get()->role->seesMoney()),
                TextColumn::make('discount_group')
                    ->label(__('Discount group'))
                    ->state(fn (Member $record, HasTable $livewire): ?string => self::pageFor($livewire)['groups'][$record->id] ?? null)
                    ->badge()
                    ->color('info')
                    ->placeholder(__('None'))
                    ->toggleable(),
                TextColumn::make('email')->label(__('Email'))->placeholder(__('None'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('gender')->label(__('Gender'))
                    ->formatStateUsing(fn (mixed $state): string => $state instanceof BackedEnum ? Badges::label($state) : (string) $state)
                    ->placeholder(__('Not given'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('date_of_birth')->label(__('Born'))->formatStateUsing(Dates::asDate())->placeholder(__('Not given'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('Registered'))->formatStateUsing(Dates::asDate(self::gymTimezone()))->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('home_branch_id')
                    ->label(__('Home branch'))
                    ->native(false)
                    ->relationship('homeBranch', 'name', self::onlyTheirBranches(...)),
                SelectFilter::make('membership_today')
                    ->label(__('Membership today'))
                    ->native(false)
                    ->options([
                        'active' => __('Has a valid membership'),
                        'ending' => __('Ending in the next 7 days'),
                        'expired' => __('Expired, nothing since'),
                        'never' => __('Never had one'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::withMembershipToday($query, $data['value'] ?? null)),
                SelectFilter::make('discount_group')
                    ->label(__('Discount group'))
                    ->native(false)
                    ->options(fn (): array => DiscountGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereIn('id', MemberDiscount::query()->select('member_id')->where('discount_group_id', (int) $data['value']))
                        : $query),
                SelectFilter::make('gender')->label(__('Gender'))->native(false)->options(Badges::options(Gender::class)),
                Tables::dateRange('registered', 'created_at', __('Registered'), isMoment: true),
            ])
            ->recordActions([
                CheckInAction::make()->button()->size('sm'),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'create' => CreateMember::route('/create'),
            'view' => ViewMember::route('/{record}'),
            'edit' => EditMember::route('/{record}/edit'),
        ];
    }

    /**
     * Fill the member from the form. The home branch isn't mass assignable,
     * so it is set through the relation, as the API does.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fillMember(Member $member, array $data): Member
    {
        $member->fill(Arr::except($data, 'home_branch_id'));
        $member->homeBranch()->associate(Branch::query()->findOrFail((int) $data['home_branch_id']));
        $member->save();

        return $member;
    }

    /**
     * The branch to preset when the staff member works at only one.
     */
    public static function onlyBranchWorkedAt(): ?int
    {
        $branches = app(CurrentStaff::class)->get()->branchesWorkedAt();

        return $branches->count() === 1 ? (int) $branches->first()?->getKey() : null;
    }

    /**
     * Members by their membership today: valid, ending within a week,
     * expired with nothing since, or never one. Looked up in Memberships,
     * which the Members domain doesn't know about.
     *
     * @param  Builder<Member>  $query
     * @return Builder<Member>
     */
    private static function withMembershipToday(Builder $query, mixed $value): Builder
    {
        $today = self::today()->toDateString();
        $running = Membership::query()->select('member_id')->notCancelled()->where('starts_on', '<=', $today)->where('ends_on', '>=', $today);
        $comingOrRunning = Membership::query()->select('member_id')->notCancelled()->where('ends_on', '>=', $today);
        $any = Membership::query()->select('member_id')->notCancelled();

        return match ($value) {
            'active' => $query->whereIn('id', $running),
            'ending' => $query->whereIn('id', (clone $running)->where('ends_on', '<=', self::today()->addDays(7)->toDateString()))
                ->whereNotIn('id', Membership::query()->select('member_id')->notCancelled()->where('starts_on', '>', $today)),
            'expired' => $query->whereIn('id', $any)->whereNotIn('id', $comingOrRunning),
            'never' => $query->whereNotIn('id', $any),
            default => $query,
        };
    }

    /**
     * One member per phone number in the gym, compared in E.164 form however
     * it was typed. Archived members free their number.
     */
    private static function phoneNotTakenInTheGym(?Member $ignoring): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoring): void {
            try {
                $phone = PhoneNumber::of(is_string($value) ? $value : '')->e164;
            } catch (InvalidPhoneNumber) {
                return;
            }

            $taken = Member::query()
                ->where('phone', $phone)
                ->when($ignoring !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoring?->getKey()))
                ->exists();

            if ($taken) {
                $fail(__('Another member of this gym already has this phone number.'));
            }
        };
    }

    /**
     * Search as the API does: part of a name, or the digits of a phone number.
     *
     * @param  Builder<Member>  $query
     * @return Builder<Member>
     */
    private static function searchMembers(Builder $query, string $search): Builder
    {
        return $query->search($search);
    }

    /**
     * Staff limited to some branches list the members whose home branch is
     * one of theirs; a search finds any member of the gym, so the desk can
     * serve a member from another branch (ADR 0025).
     *
     * @param  Builder<Member>  $query
     * @return Builder<Member>
     */
    private static function listedMembers(Builder $query, ListRecords $livewire): Builder
    {
        $query->with('homeBranch');

        return blank($livewire->getTableSearch()) ? $query->homeAtAnyOf(self::staffBranchIds()) : $query;
    }

    /**
     * The page's memberships at a glance, balances and discount groups, in
     * one query each for all its members.
     *
     * @return array{key: string, memberships: array<int, ?Membership>, balances: array<int, Money>, groups: array<int, string>}
     */
    private static function pageFor(HasTable $livewire): array
    {
        $memberIds = array_map(fn (Model $member): int => (int) $member->getKey(), Tables::pageRecords($livewire));
        $key = implode(',', $memberIds);

        if (self::$page !== null && self::$page['key'] === $key) {
            return self::$page;
        }

        $memberships = Membership::atAGlanceFor($memberIds, self::today());

        return self::$page = [
            'key' => $key,
            'memberships' => $memberships->all(),
            'balances' => app(CurrentStaff::class)->get()->role->seesMoney() ? Payment::balancesDueOf($memberIds) : [],
            'groups' => MemberDiscount::query()
                ->with('discountGroup')
                ->whereIn('member_id', $memberIds)
                ->get()
                ->filter(fn (MemberDiscount $memberDiscount): bool => $memberDiscount->discountGroup !== null)
                ->mapWithKeys(fn (MemberDiscount $memberDiscount): array => [$memberDiscount->member_id => (string) $memberDiscount->discountGroup?->name])
                ->all(),
        ];
    }

    private static function membershipOf(Member $member, ?HasTable $livewire = null): ?Membership
    {
        if ($livewire !== null) {
            return self::pageFor($livewire)['memberships'][$member->id] ?? null;
        }

        return Membership::atAGlanceFor([$member->id], self::today())->get($member->id);
    }

    private static function statusTodayOf(Member $member, ?HasTable $livewire = null): ?MembershipStatus
    {
        return self::membershipOf($member, $livewire)?->statusOn(self::today());
    }

    /**
     * "Active · Monthly", "Expired · Monthly" or "None".
     */
    private static function membershipSummaryOf(Member $member, ?HasTable $livewire = null): string
    {
        $membership = self::membershipOf($member, $livewire);

        return $membership === null ? __('None') : Badges::label($membership->statusOn(self::today())).' · '.$membership->plan?->name;
    }

    /**
     * The member's discount group and until when, looked up in Memberships,
     * which the Members domain doesn't know about (ADR 0032).
     */
    private static function discountGroupOf(Member $member): ?string
    {
        $memberDiscount = MemberDiscount::of($member);
        $group = $memberDiscount?->discountGroup;

        if ($memberDiscount === null || $group === null) {
            return null;
        }

        return $memberDiscount->until === null
            ? __(':group, :discount off', ['group' => $group->name, 'discount' => $group->discount])
            : __(':group, :discount off, until :date', ['group' => $group->name, 'discount' => $group->discount, 'date' => Dates::short($memberDiscount->until)]);
    }

    /**
     * When the member was last let in, looked up in Attendance.
     */
    private static function lastVisitOf(Member $member): ?CarbonImmutable
    {
        $lastVisit = CheckIn::query()->where('member_id', $member->id)->where('result', CheckInResult::Allowed)->max('checked_in_at');

        return $lastVisit === null ? null : CarbonImmutable::parse((string) $lastVisit);
    }

    /**
     * What the member still owes across their memberships. It is looked up
     * here because the Members domain doesn't know about Billing.
     */
    private static function balanceDueOf(Member $member): Money
    {
        return Payment::balancesDueOf([$member->id])[$member->id] ?? Money::zero();
    }

    private static function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }
}
