<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GymInvoices;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Exceptions\BillingRefused;
use App\Domains\PlatformBilling\Models\GymInvoice;
use App\Domains\Shared\Money\Money;
use App\Filament\Admin\Resources\GymInvoices\Pages\ListGymInvoices;
use App\Filament\Staff\Support\Tables;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every invoice the platform has issued to gyms, for platform admins: what
 * each gym owes, when it is due, and what was paid (ADR 0045).
 */
class GymInvoiceResource extends Resource
{
    protected static ?string $model = GymInvoice::class;

    protected static ?string $modelLabel = 'invoice';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'invoices';

    protected static bool $shouldSkipAuthorization = true;

    /** @var array<string, string> */
    public const STATUSES = ['due' => 'Due', 'overdue' => 'Overdue', 'paid' => 'Paid', 'voided' => 'Voided'];

    /** @var array<string, string> */
    public const STATUS_COLORS = ['due' => 'warning', 'overdue' => 'danger', 'paid' => 'success', 'voided' => 'gray'];

    /**
     * @return Builder<GymInvoice>
     */
    public static function getEloquentQuery(): Builder
    {
        return GymInvoice::query()->acrossOrganizations()->with('organization');
    }

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedDocumentText, 'No invoices yet', 'Invoices are issued a week before each billed gym\'s period starts.')
            ->defaultSort('number', 'desc')
            ->searchPlaceholder('Invoice number or gym')
            ->searchable()
            ->columns([
                TextColumn::make('number')
                    ->label('Invoice')
                    ->formatStateUsing(fn (GymInvoice $record): string => $record->reference())
                    ->weight(FontWeight::SemiBold)
                    ->fontFamily('mono')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $query) use ($search): void {
                        $digits = (int) preg_replace('/\D/', '', $search);

                        if ($digits > 0) {
                            $query->orWhere('number', $digits);
                        }

                        $query->orWhereIn('organization_id', Organization::query()->whereRaw('name ILIKE ?', ['%'.$search.'%'])->select('id'));
                    }))
                    ->sortable(),
                TextColumn::make('organization.name')->label('Gym'),
                TextColumn::make('period')
                    ->state(fn (GymInvoice $record): string => $record->period_starts_on->format('j M Y').' – '.$record->period_ends_on->format('j M Y'))
                    ->description(fn (GymInvoice $record): string => $record->plan_name.' · '.$record->branches.' '.($record->branches === 1 ? 'branch' : 'branches').' · '.$record->months.' '.($record->months === 1 ? 'month' : 'months'))
                    ->color('gray'),
                Tables::money('total', fn (GymInvoice $record): Money => $record->total)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('total_minor', $direction)),
                TextColumn::make('due_on')->label('Due')->date('j M Y')->sortable(),
                TextColumn::make('status')
                    ->state(fn (GymInvoice $record): string => self::statusOf($record))
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state])
                    ->badge()
                    ->color(fn (string $state): string => self::STATUS_COLORS[$state]),
                TextColumn::make('pauses_on')->label('Pauses the gym on')->date('j M Y')->toggleable(),
                TextColumn::make('issued_on')->label('Issued')->date('j M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->native(false)
                    ->options(self::STATUSES)
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'due' => $query->whereNull('paid_at')->whereNull('voided_at')->whereRaw('due_on >= (SELECT (now() AT TIME ZONE timezone)::date FROM organizations WHERE organizations.id = gym_invoices.organization_id)'),
                        'overdue' => $query->whereNull('paid_at')->whereNull('voided_at')->whereRaw('due_on < (SELECT (now() AT TIME ZONE timezone)::date FROM organizations WHERE organizations.id = gym_invoices.organization_id)'),
                        'paid' => $query->whereNotNull('paid_at'),
                        'voided' => $query->whereNotNull('voided_at'),
                        default => $query,
                    }),
                SelectFilter::make('gym')
                    ->native(false)
                    ->searchable()
                    ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->map(fn (mixed $name): string => (string) $name)->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('organization_id', (int) $data['value']) : $query),
                Tables::dateRange('due', 'due_on', 'Due'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (GymInvoice $record): string => route('platform-bill.invoice', $record->getKey()))
                    ->openUrlInNewTab(),
                self::voidAction(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGymInvoices::route('/'),
        ];
    }

    /**
     * Due, overdue, paid or voided, in the gym's own today.
     */
    public static function statusOf(GymInvoice $invoice): string
    {
        return match (true) {
            $invoice->voided_at !== null => 'voided',
            $invoice->paid_at !== null => 'paid',
            $invoice->isOverdueOn($invoice->organization->today()) => 'overdue',
            default => 'due',
        };
    }

    public static function voidAction(): Action
    {
        return Action::make('void')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (GymInvoice $record): bool => $record->isOpen())
            ->modalIcon(Heroicon::OutlinedXCircle)
            ->modalHeading(fn (GymInvoice $record): string => 'Void '.$record->reference().'?')
            ->modalDescription('The gym no longer owes it. If it is the gym\'s latest invoice, its period is issued again on the next run, with the gym\'s plan and prices as they are then.')
            ->modalSubmitActionLabel('Void invoice')
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->placeholder('Wrong price: they agreed 1,800 a month.')
                    ->required()
                    ->maxLength(1000),
            ])
            ->action(function (GymInvoice $record, array $data, Action $action): void {
                $admin = Filament::auth()->user();
                abort_unless($admin instanceof User, 403);

                try {
                    $record->void($admin, (string) $data['reason']);
                } catch (BillingRefused $refused) {
                    Notification::make()->danger()->title($refused->getMessage())->send();
                    $action->halt();
                }

                Notification::make()->success()->title('Invoice voided.')->send();
            });
    }
}
