<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PlatformPlans;

use App\Domains\PlatformBilling\GymCharge;
use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Domains\Shared\Money\Exceptions\InvalidMoneyAmount;
use App\Domains\Shared\Money\Money;
use App\Filament\Admin\Resources\PlatformPlans\Pages\CreatePlatformPlan;
use App\Filament\Admin\Resources\PlatformPlans\Pages\EditPlatformPlan;
use App\Filament\Admin\Resources\PlatformPlans\Pages\ListPlatformPlans;
use App\Filament\Staff\Support\Tables;
use App\Rules\ValidMoneyAmount;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
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

/**
 * What the platform charges gyms: plans with a monthly price for the first
 * branch, a price for each extra branch and discounts for paying ahead.
 * Being able to open the admin panel is the authorization. See ADR 0045.
 */
class PlatformPlanResource extends Resource
{
    protected static ?string $model = PlatformPlan::class;

    protected static ?string $modelLabel = 'plan';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'plans';

    protected static bool $shouldSkipAuthorization = true;

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedRectangleStack, 'No plans yet', 'Add a plan with New plan, then put gyms on it.')
            ->defaultSort('monthly_price_minor')
            ->searchPlaceholder('Plan name')
            ->columns([
                TextColumn::make('name')->weight(FontWeight::SemiBold)->searchable()->sortable(),
                Tables::money('monthly_price', fn (PlatformPlan $record): Money => $record->monthly_price, 'A month, first branch')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('monthly_price_minor', $direction)),
                Tables::money('extra_branch_price', fn (PlatformPlan $record): Money => $record->extra_branch_price, 'Each extra branch'),
                TextColumn::make('discounts')
                    ->label('Paying ahead')
                    ->state(fn (PlatformPlan $record): string => self::discountsOf($record))
                    ->color('gray'),
                TextColumn::make('status')
                    ->state(fn (PlatformPlan $record): string => $record->isRetired() ? 'Retired' : 'Offered')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Retired' ? 'gray' : 'success')
                    ->icon(fn (string $state): Heroicon => $state === 'Retired' ? Heroicon::OutlinedArchiveBox : Heroicon::OutlinedCheckCircle),
                TextColumn::make('created_at')->label('Added')->date('j M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->native(false)
                    ->options(['offered' => 'Offered', 'retired' => 'Retired'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'offered' => $query->whereNull('retired_at'),
                        'retired' => $query->whereNotNull('retired_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                self::retireAction(),
                self::offerAgainAction(),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The plan')
                ->description('Prices include VAT. A change applies to each gym\'s next invoice; invoices already issued stay as they are.')
                ->icon(Heroicon::OutlinedRectangleStack)
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextInput::make('name')
                        ->placeholder('Standard')
                        ->required()
                        ->maxLength(255)
                        ->rules(fn (?PlatformPlan $record): array => [self::nameNoOtherPlanHas($record)]),
                    TextInput::make('monthly_price')
                        ->label('A month, first branch')
                        ->prefix('ETB')
                        ->placeholder('2,000')
                        ->required()
                        ->live(onBlur: true)
                        ->rules([new ValidMoneyAmount]),
                    TextInput::make('extra_branch_price')
                        ->label('A month, each extra branch')
                        ->prefix('ETB')
                        ->placeholder('500')
                        ->default('0')
                        ->required()
                        ->live(onBlur: true)
                        ->rules([new ValidMoneyAmount])
                        ->helperText('0 if extra branches cost nothing more.'),
                ]),
            Section::make('Paying ahead')
                ->description('A discount for a gym that pays 3, 6 or 12 months in one invoice.')
                ->icon(Heroicon::OutlinedReceiptPercent)
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    self::discountInput('discount_3_months', '3 months'),
                    self::discountInput('discount_6_months', '6 months'),
                    self::discountInput('discount_12_months', '12 months'),
                    TextEntry::make('preview')
                        ->label('What a gym pays')
                        ->columnSpanFull()
                        ->icon(Heroicon::OutlinedCalculator)
                        ->color('gray')
                        ->listWithLineBreaks()
                        ->state(fn (Get $get): array => self::previewOf($get)),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformPlans::route('/'),
            'create' => CreatePlatformPlan::route('/new'),
            'edit' => EditPlatformPlan::route('/{record}/edit'),
        ];
    }

    /**
     * The plan's fields from the form, prices as Money.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fieldsFrom(array $data): array
    {
        return [
            'name' => trim((string) $data['name']),
            'monthly_price' => Money::of((string) $data['monthly_price']),
            'extra_branch_price' => Money::of((string) $data['extra_branch_price']),
            'discount_3_months' => (int) ($data['discount_3_months'] ?? 0),
            'discount_6_months' => (int) ($data['discount_6_months'] ?? 0),
            'discount_12_months' => (int) ($data['discount_12_months'] ?? 0),
        ];
    }

    public static function retireAction(): Action
    {
        return Action::make('retire')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedArchiveBox)
            ->modalHeading(fn (PlatformPlan $record): string => 'Retire '.$record->name.'?')
            ->modalDescription('It is no longer offered for gyms. Gyms already on it stay on it until you change their plan.')
            ->visible(fn (PlatformPlan $record): bool => ! $record->isRetired())
            ->action(function (PlatformPlan $record): void {
                $record->retire();

                Notification::make()->success()->title('Plan retired.')->send();
            });
    }

    public static function offerAgainAction(): Action
    {
        return Action::make('offerAgain')
            ->label('Offer again')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (PlatformPlan $record): bool => $record->isRetired())
            ->action(function (PlatformPlan $record): void {
                $record->offerAgain();

                Notification::make()->success()->title('Plan offered again.')->send();
            });
    }

    private static function discountInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->integer()
            ->minValue(0)
            ->maxValue(50)
            ->default(0)
            ->suffix('% off')
            ->required()
            ->live(onBlur: true);
    }

    /**
     * "10% off 3 months · 15% off 12 months", or "No discounts".
     */
    private static function discountsOf(PlatformPlan $plan): string
    {
        $discounts = [];

        foreach ([3, 6, 12] as $months) {
            if ($plan->discountFor($months) > 0) {
                $discounts[] = $plan->discountFor($months).'% off '.$months.' months';
            }
        }

        return $discounts === [] ? 'No discounts' : implode(' · ', $discounts);
    }

    /**
     * What a gym with one branch pays for a month, and one with three for a
     * year, from the form as it is being filled in.
     *
     * @return list<string>
     */
    private static function previewOf(Get $get): array
    {
        try {
            $monthly = Money::of((string) $get('monthly_price'));
            $extra = Money::of(filled($get('extra_branch_price')) ? (string) $get('extra_branch_price') : '0');
        } catch (InvalidMoneyAmount) {
            return ['Fill in the prices to see what a gym pays.'];
        }

        $yearDiscount = is_numeric($get('discount_12_months')) ? max(0, min(50, (int) $get('discount_12_months'))) : 0;
        $lines = [];

        foreach ([[1, 1, 0], [3, 12, $yearDiscount]] as [$branches, $months, $percent]) {
            $charge = new GymCharge($monthly, $extra, $branches, $months, $percent);
            $lines[] = sprintf(
                '%d %s, %d %s: %s (VAT inside: %s)',
                $branches,
                $branches === 1 ? 'branch' : 'branches',
                $months,
                $months === 1 ? 'month' : 'months',
                $charge->total->format(),
                $charge->vat->format(),
            );
        }

        return $lines;
    }

    private static function nameNoOtherPlanHas(?PlatformPlan $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            $query = PlatformPlan::query()->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))]);

            if ($record !== null) {
                $query->whereKeyNot($record->getKey());
            }

            if ($query->exists()) {
                $fail('Another plan already has this name.');
            }
        };
    }
}
