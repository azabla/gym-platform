<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Memberships;

use App\Domains\Billing\Balances\MembershipBalance;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Memberships\Enums\MembershipStatus;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\Concerns\LimitsToTheStaffMembersBranches;
use App\Filament\Staff\Resources\Concerns\ShowsGymTime;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Filament\Staff\Resources\Memberships\Pages\ListMemberships;
use App\Filament\Staff\Resources\Memberships\Pages\ViewMembership;
use App\Filament\Staff\Resources\PaymentLinks\PaymentLinkResource;
use App\Filament\Staff\Support\Badges;
use App\Filament\Staff\Support\Dates;
use App\Filament\Staff\Support\Tables;
use App\Filament\Staff\Support\TranslatesItsLabels;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * The gym's memberships, to look up, with their status on the gym's today,
 * what was paid and what is owed (worked out once per page), and where any
 * discount came from. Selling, freezing and cancelling happen on a member's
 * or a membership's page. See ADR 0017, ADR 0018 and ADR 0033.
 */
class MembershipResource extends Resource
{
    use LimitsToTheStaffMembersBranches;
    use ShowsGymTime;
    use TranslatesItsLabels;

    protected static ?string $model = Membership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::FrontDesk;

    protected static ?int $navigationSort = 2;

    /**
     * The current page's balances, keyed by membership id.
     *
     * @var array{key: string, balances: array<int, MembershipBalance>}|null
     */
    private static ?array $page = null;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('At a glance'))
                    ->icon(Heroicon::OutlinedSparkles)
                    ->schema([
                        Grid::make(['default' => 2, 'lg' => 4])->schema([
                            TextEntry::make('status')->label(__('Status'))
                                ->badge()
                                ->state(fn (Membership $record): MembershipStatus => self::statusOf($record))
                                ->formatStateUsing(fn (MembershipStatus $state): string => Badges::label($state))
                                ->color(fn (MembershipStatus $state): string => Badges::color($state))
                                ->icon(fn (MembershipStatus $state): ?Heroicon => Badges::icon($state)),
                            TextEntry::make('price')->label(__('Price'))
                                ->state(fn (Membership $record): string => $record->price->format())
                                ->weight(FontWeight::Bold)
                                ->helperText(fn (Membership $record): ?string => $record->discount_reason === null ? null : __('List :price, :reason', ['price' => $record->list_price->format(), 'reason' => $record->discount_reason])),
                            TextEntry::make('amount_paid')
                                ->label(__('Paid'))
                                ->state(fn (Membership $record): string => self::balanceOf($record)->amountPaid->format())
                                ->color('success')
                                ->weight(FontWeight::Bold),
                            TextEntry::make('balance_due')
                                ->label(__('Still owed'))
                                ->state(fn (Membership $record): string => self::balanceOf($record)->balanceDue->format())
                                ->color(fn (Membership $record): string => self::balanceOf($record)->balanceDue->isPositive() ? 'danger' : 'gray')
                                ->weight(FontWeight::Bold),
                        ]),
                    ]),
                Section::make(__('Membership'))
                    ->icon(Heroicon::OutlinedIdentification)
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('member.full_name')
                            ->label(__('Member'))
                            ->weight(FontWeight::SemiBold)
                            ->url(fn (Membership $record): string => MemberResource::getUrl('view', ['record' => $record->member_id])),
                        TextEntry::make('plan.name')->label(__('Plan'))->badge()->color('primary'),
                        TextEntry::make('sold')
                            ->label(__('Sold'))
                            ->state(fn (Membership $record): string => ($record->sold_in_app ? __('In the app') : __('By :name', ['name' => (string) $record->soldBy?->user?->name]))
                                .($record->created_at === null ? '' : ', '.Dates::short($record->created_at->setTimezone(self::gymTimezone())))),
                        TextEntry::make('starts_on')->label(__('Starts'))->formatStateUsing(Dates::asDate()),
                        TextEntry::make('ends_on')->label(__('Ends'))->formatStateUsing(Dates::asDate()),
                        TextEntry::make('visit_limit')
                            ->label(__('Visits'))
                            ->formatStateUsing(fn (?int $state): string => $state === null ? __('No limit') : __(':count visits', ['count' => $state]))
                            ->placeholder(__('No limit')),
                    ]),
                Section::make(__('Freezes'))
                    ->icon(Heroicon::OutlinedPauseCircle)
                    ->columns(['default' => 1, 'md' => 3])
                    ->collapsible()
                    ->schema([
                        TextEntry::make('freeze_days_left')
                            ->label(__('Freeze days left'))
                            ->state(fn (Membership $record): string => __(':left of :allowed', ['left' => max(0, $record->freeze_days_allowed - $record->freezeDaysUsed()), 'allowed' => $record->freeze_days_allowed])),
                        TextEntry::make('freezes')->label(__('Freezes'))
                            ->columnSpan(2)
                            ->state(fn (Membership $record): array => $record->freezes()->orderBy('starts_on')->get()->map(
                                fn (MembershipFreeze $freeze): string => __(':from to :to: :reason', ['from' => Dates::short($freeze->starts_on), 'to' => Dates::short($freeze->ends_on), 'reason' => (string) $freeze->reason])
                                    .($freeze->isFromTheApp() ? ' '.__('(by the member, in the app)') : ''),
                            )->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder(__('None')),
                        TextEntry::make('cancelled_at')
                            ->label(__('Cancelled'))
                            ->formatStateUsing(Dates::asDateTime(self::gymTimezone()))
                            ->color('danger')
                            ->visible(fn (Membership $record): bool => $record->cancelled_at !== null),
                    ]),
                Section::make(__('Open payment link'))
                    ->description(__('Waiting for the member to pay on Chapa. Copy it, or send it by SMS from the top of the page.'))
                    ->icon(Heroicon::OutlinedLink)
                    ->columns(2)
                    ->visible(fn (Membership $record): bool => self::openPaymentLinkOf($record) !== null)
                    ->schema([
                        TextEntry::make('open_payment_link_amount')
                            ->label(__('Amount'))
                            ->weight(FontWeight::Bold)
                            ->state(fn (Membership $record): ?string => self::openPaymentLinkOf($record)?->amount->format())
                            ->url(fn (Membership $record): ?string => ($paymentLink = self::openPaymentLinkOf($record)) === null
                                ? null
                                : PaymentLinkResource::getUrl('view', ['record' => $paymentLink])),
                        TextEntry::make('open_payment_link_url')
                            ->label(__('Link to send the member'))
                            ->state(fn (Membership $record): ?string => self::openPaymentLinkOf($record)?->checkout_url)
                            ->copyable()
                            ->copyMessage(__('Copied')),
                    ]),
            ]);
    }

    /**
     * The membership's open payment link, at the staff member's branches
     * (ADR 0025 and ADR 0031). Looked up here because Memberships doesn't
     * depend on Billing.
     */
    public static function openPaymentLinkOf(Membership $membership): ?PaymentLink
    {
        return PaymentLink::query()
            ->pending()
            ->forAnyOf(self::staffBranchIds())
            ->where('membership_id', $membership->id)
            ->first();
    }

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedIdentification, __('No memberships here'), __('Sell one from a member\'s page, or change the filters.'))
            ->defaultSort('starts_on', 'desc')
            ->modifyQueryUsing(self::listedMemberships(...))
            ->searchPlaceholder(__('Member name'))
            ->columns([
                TextColumn::make('member.full_name')
                    ->label(__('Member'))
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Membership $record): string => (string) $record->member?->phone)
                    ->searchable(),
                TextColumn::make('plan.name')->label(__('Plan'))->badge()->color('primary'),
                Tables::badge('status')
                    ->state(fn (Membership $record): MembershipStatus => self::statusOf($record)),
                TextColumn::make('starts_on')->label(__('Starts'))->formatStateUsing(Dates::asDate())->sortable(),
                TextColumn::make('ends_on')->label(__('Ends'))->formatStateUsing(Dates::asDate())->sortable(),
                Tables::money('price', fn (Membership $record) => $record->price)
                    ->description(fn (Membership $record): ?string => $record->discount_reason === null ? null : __('List :price', ['price' => $record->list_price->format()])),
                Tables::money('amount_paid', fn (Membership $record, HasTable $livewire) => self::pageBalanceOf($record, $livewire)->amountPaid, __('Paid'))
                    ->color('success')
                    ->toggleable(),
                Tables::money('balance_due', fn (Membership $record, HasTable $livewire) => self::pageBalanceOf($record, $livewire)->balanceDue, __('Owed'))
                    ->color(fn (Membership $record, HasTable $livewire): string => self::pageBalanceOf($record, $livewire)->balanceDue->isPositive() ? 'danger' : 'gray'),
                TextColumn::make('discount')->label(__('Discount'))
                    ->state(fn (Membership $record): ?string => self::discountSourceOf($record))
                    ->badge()
                    ->color('info')
                    ->placeholder(__('None'))
                    ->tooltip(fn (Membership $record): ?string => $record->discount_reason)
                    ->toggleable(),
                TextColumn::make('member.homeBranch.name')->label(__('Home branch'))->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sold_by')
                    ->label(__('Sold by'))
                    ->state(fn (Membership $record): string => $record->sold_in_app ? __('In the app') : (string) $record->soldBy?->user?->name)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('Sold on'))->formatStateUsing(Dates::asDate(self::gymTimezone()))->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('member')->label(__('Member'))
                    ->native(false)
                    ->relationship('member', 'full_name')
                    ->searchable(),
                SelectFilter::make('status')->label(__('Status'))
                    ->native(false)
                    ->options(Badges::options(MembershipStatus::class))
                    ->query(fn (Builder $query, array $data): Builder => self::withStatus($query, $data['value'] ?? null)),
                SelectFilter::make('plan')->label(__('Plan'))
                    ->native(false)
                    ->relationship('plan', 'name', fn (Builder $query): Builder => $query->withoutGlobalScope(SoftDeletingScope::class)),
                SelectFilter::make('home_branch')
                    ->label(__('Home branch'))
                    ->native(false)
                    ->options(fn (): array => self::onlyTheirBranches(Branch::query())->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereRelation('member', 'home_branch_id', (int) $data['value'])
                        : $query),
                SelectFilter::make('ending_within')
                    ->label(__('Ending within'))
                    ->native(false)
                    ->options(['7' => __('The next 7 days'), '30' => __('The next 30 days')])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereNull('cancelled_at')
                            ->where('ends_on', '>=', self::today()->toDateString())
                            ->where('ends_on', '<=', self::today()->addDays((int) $data['value'])->toDateString())
                        : $query),
                SelectFilter::make('discount')->label(__('Discount'))
                    ->native(false)
                    ->options([
                        'any' => __('Any discount'),
                        'typed' => __('Typed at the desk'),
                        'promo_code' => __('Promo code'),
                        'discount_group' => __('Discount group'),
                        'none' => __('Full price'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::withDiscount($query, $data['value'] ?? null)),
                TernaryFilter::make('sold_in_app')
                    ->label(__('Where sold'))
                    ->native(false)
                    ->placeholder(__('Anywhere'))
                    ->trueLabel(__('In the app'))
                    ->falseLabel(__('At the desk')),
                Tables::dateRange('sold', 'created_at', __('Sold'), isMoment: true),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMemberships::route('/'),
            'view' => ViewMembership::route('/{record}'),
        ];
    }

    /**
     * What is paid and owed on the membership, from Billing, looked up once
     * for the page however many entries show it.
     */
    private static function balanceOf(Membership $membership): MembershipBalance
    {
        if (! $membership->relationLoaded('balance')) {
            $membership->setRelation('balance', Payment::balancesOf([$membership])[$membership->id]);
        }

        /** @var MembershipBalance */
        return $membership->getRelation('balance');
    }

    /**
     * The balance of a membership in the list, from one query for the page.
     */
    private static function pageBalanceOf(Membership $membership, HasTable $livewire): MembershipBalance
    {
        $memberships = array_values(array_filter(Tables::pageRecords($livewire), fn ($record): bool => $record instanceof Membership));
        $key = implode(',', array_map(fn (Membership $membership): int => $membership->id, $memberships));

        if (self::$page === null || self::$page['key'] !== $key) {
            self::$page = ['key' => $key, 'balances' => Payment::balancesOf($memberships)];
        }

        return self::$page['balances'][$membership->id] ?? self::balanceOf($membership);
    }

    /**
     * Where the membership's discount came from, if any.
     */
    private static function discountSourceOf(Membership $membership): ?string
    {
        return match (true) {
            $membership->discount_reason === null => null,
            $membership->promo_code_id !== null => __('Promo code'),
            $membership->discount_group_id !== null => __('Group'),
            default => __('Typed'),
        };
    }

    private static function statusOf(Membership $membership): MembershipStatus
    {
        return $membership->statusOn(self::today());
    }

    /**
     * Narrow to the memberships with the status on the gym's today, as
     * Membership::statusOn() works it out.
     *
     * @param  Builder<Membership>  $query
     * @return Builder<Membership>
     */
    private static function withStatus(Builder $query, ?string $status): Builder
    {
        $today = self::today()->toDateString();
        $frozenToday = fn ($freezes) => $freezes->where('starts_on', '<=', $today)->where('ends_on', '>=', $today);
        $current = fn (Builder $query): Builder => $query->whereNull('cancelled_at')
            ->where('starts_on', '<=', $today)
            ->where('ends_on', '>=', $today);

        return match (MembershipStatus::tryFrom((string) $status)) {
            null => $query,
            MembershipStatus::Cancelled => $query->whereNotNull('cancelled_at'),
            MembershipStatus::Upcoming => $query->whereNull('cancelled_at')->where('starts_on', '>', $today),
            MembershipStatus::Expired => $query->whereNull('cancelled_at')->where('ends_on', '<', $today),
            MembershipStatus::Frozen => $current($query)->whereHas('freezes', $frozenToday),
            MembershipStatus::Active => $current($query)->whereDoesntHave('freezes', $frozenToday),
        };
    }

    /**
     * Narrow to the memberships by where their discount came from.
     *
     * @param  Builder<Membership>  $query
     * @return Builder<Membership>
     */
    private static function withDiscount(Builder $query, mixed $source): Builder
    {
        return match ($source) {
            'any' => $query->whereNotNull('discount_reason'),
            'typed' => $query->whereNotNull('discount_reason')->whereNull('promo_code_id')->whereNull('discount_group_id'),
            'promo_code' => $query->whereNotNull('promo_code_id'),
            'discount_group' => $query->whereNotNull('discount_group_id'),
            'none' => $query->whereNull('discount_reason'),
            default => $query,
        };
    }

    /**
     * Staff limited to some branches list the memberships of members whose
     * home branch is one of theirs, unless the list is filtered to one member
     * from the member's page: a member from another branch is served at any
     * desk (ADR 0025).
     *
     * @param  Builder<Membership>  $query
     * @return Builder<Membership>
     */
    private static function listedMemberships(Builder $query, ListRecords $livewire): Builder
    {
        $query->with(['member.homeBranch', 'plan', 'freezes', 'soldBy.user']);

        return filled($livewire->getTableFilterState('member')['value'] ?? null)
            ? $query
            : $query->ofMembersAtAnyOf(self::staffBranchIds());
    }

    private static function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }
}
