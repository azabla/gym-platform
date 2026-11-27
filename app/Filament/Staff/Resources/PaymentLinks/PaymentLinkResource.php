<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\PaymentLinks;

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\PaymentLink;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\Concerns\LimitsToTheStaffMembersBranches;
use App\Filament\Staff\Resources\Concerns\ShowsGymTime;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Filament\Staff\Resources\PaymentLinks\Pages\ListPaymentLinks;
use App\Filament\Staff\Resources\PaymentLinks\Pages\ViewPaymentLink;
use App\Filament\Staff\Resources\Payments\PaymentResource;
use App\Filament\Staff\Support\Badges;
use App\Filament\Staff\Support\Tables;
use App\Filament\Staff\Support\TranslatesItsLabels;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Links to Chapa's payment page, made at the desk or by members in the app,
 * as the API's payment links list shows them. A pending link's checkout
 * address is there to copy and send; Check now asks Chapa whether it was
 * paid, and Cancel withdraws it. Trainers don't handle money, so the payment
 * link policy keeps this from them. See ADR 0009 and ADR 0029.
 */
class PaymentLinkResource extends Resource
{
    use LimitsToTheStaffMembersBranches;
    use ShowsGymTime;
    use TranslatesItsLabels;

    protected static ?string $model = PaymentLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Money;

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('At a glance')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->schema([
                        Grid::make(['default' => 2, 'lg' => 4])->schema([
                            TextEntry::make('amount')
                                ->state(fn (PaymentLink $record): string => $record->amount->format())
                                ->size('lg')
                                ->weight(FontWeight::Bold),
                            TextEntry::make('status')
                                ->badge()
                                ->formatStateUsing(fn (PaymentLinkStatus $state): string => Badges::label($state))
                                ->color(fn (PaymentLinkStatus $state): string => Badges::color($state))
                                ->icon(fn (PaymentLinkStatus $state): ?Heroicon => Badges::icon($state)),
                            TextEntry::make('member.full_name')
                                ->label('Member')
                                ->weight(FontWeight::SemiBold)
                                ->url(fn (PaymentLink $record): string => MemberResource::getUrl('view', ['record' => $record->member_id])),
                            TextEntry::make('for')->state(fn (PaymentLink $record): string => self::whatItIsFor($record))->badge()->color('primary'),
                        ]),
                        TextEntry::make('checkout_url')
                            ->label('Link to send the member')
                            ->copyable()
                            ->copyMessage('Copied')
                            ->icon(Heroicon::OutlinedLink)
                            ->visible(fn (PaymentLink $record): bool => $record->status === PaymentLinkStatus::Pending),
                    ]),
                Section::make('Details')
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('branch.name')->label('Branch')->badge()->color('gray'),
                        TextEntry::make('createdBy.user.name')->label('Created by')->placeholder('The member, in the app'),
                        TextEntry::make('created_at')->label('Created')->dateTime('j M Y, H:i')->timezone(self::gymTimezone()),
                        TextEntry::make('payment.receipt_number')
                            ->label('Receipt')
                            ->placeholder('None')
                            ->url(fn (PaymentLink $record): ?string => $record->payment_id === null ? null : PaymentResource::getUrl('view', ['record' => $record->payment_id])),
                        TextEntry::make('chapa_reference')->label('Chapa reference')->copyable()->placeholder('None'),
                        TextEntry::make('sms_sent_at')->label('Texted to the member')->dateTime('j M Y, H:i')->timezone(self::gymTimezone())->placeholder('Not yet'),
                        TextEntry::make('cancelled_at')
                            ->label('Cancelled')
                            ->dateTime('j M Y, H:i')
                            ->timezone(self::gymTimezone())
                            ->visible(fn (PaymentLink $record): bool => $record->cancelled_at !== null),
                    ]),
                Section::make('Refund')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->columns(['default' => 1, 'md' => 3])
                    ->visible(fn (PaymentLink $record): bool => $record->refunded_at !== null)
                    ->schema([
                        TextEntry::make('refunded_at')->label('Refunded')->dateTime('j M Y, H:i')->timezone(self::gymTimezone()),
                        TextEntry::make('refundedBy.user.name')->label('Refunded by'),
                        TextEntry::make('refund_note')->label('Refund note')->placeholder('None'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedLink, 'No payment links here', 'Create one from a membership\'s page to let the member pay online, or change the filters.')
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['member', 'plan', 'membership.plan', 'branch', 'createdBy.user']))
            ->searchPlaceholder('Member name')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('j M Y, H:i')
                    ->timezone(self::gymTimezone())
                    ->description(fn (PaymentLink $record): string => (string) $record->created_at?->diffForHumans())
                    ->sortable(),
                TextColumn::make('member.full_name')->label('Member')->weight(FontWeight::SemiBold)->searchable(),
                TextColumn::make('for')
                    ->state(fn (PaymentLink $record): string => self::whatItIsFor($record))
                    ->badge()
                    ->color(fn (PaymentLink $record): string => $record->isForAPlan() ? 'info' : 'primary'),
                Tables::money('amount', fn (PaymentLink $record) => $record->amount),
                Tables::badge('status'),
                IconColumn::make('texted')
                    ->label('Texted')
                    ->state(fn (PaymentLink $record): bool => $record->sms_sent_at !== null)
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                    ->falseIcon(Heroicon::OutlinedMinus)
                    ->trueColor('info')
                    ->falseColor('gray')
                    ->toggleable(),
                TextColumn::make('branch.name')->label('Branch')->badge()->color('gray'),
                TextColumn::make('createdBy.user.name')->label('Created by')->placeholder('In the app')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->native(false)->options(Badges::options(PaymentLinkStatus::class)),
                SelectFilter::make('branch')->native(false)->relationship('branch', 'name', self::onlyTheirBranches(...)),
                SelectFilter::make('for')
                    ->native(false)
                    ->options(['membership' => 'Paying a membership', 'plan' => 'Buying a plan in the app'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'membership' => $query->whereNull('plan_id'),
                        'plan' => $query->whereNotNull('plan_id'),
                        default => $query,
                    }),
                TernaryFilter::make('texted')
                    ->native(false)
                    ->attribute('sms_sent_at')
                    ->nullable()
                    ->placeholder('Texted or not')
                    ->trueLabel('Texted to the member')
                    ->falseLabel('Not texted'),
                Tables::dateRange('created', 'created_at', 'Created', isMoment: true),
                SelectFilter::make('member')
                    ->native(false)
                    ->relationship('member', 'full_name')
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * Only the links of the staff member's branches: another branch's link
     * isn't listed or opened (ADR 0025). Link queries are already limited to
     * the current gym (ADR 0003).
     *
     * @return Builder<PaymentLink>
     */
    public static function getEloquentQuery(): Builder
    {
        return PaymentLink::query()->forAnyOf(self::staffBranchIds());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentLinks::route('/'),
            'view' => ViewPaymentLink::route('/{record}'),
        ];
    }

    /**
     * A membership being paid, or a plan being bought in the app.
     */
    private static function whatItIsFor(PaymentLink $paymentLink): string
    {
        return $paymentLink->isForAPlan() ? "Buying {$paymentLink->planName()}" : $paymentLink->planName();
    }
}
