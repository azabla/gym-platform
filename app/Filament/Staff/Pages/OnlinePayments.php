<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Domains\Billing\Chapa\Exceptions\ChapaRequestFailed;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\Concerns\ShowsGymTime;
use App\Filament\Staff\Support\Badges;
use App\Filament\Staff\Support\Dates;
use App\Filament\Staff\Support\TranslatesItsTitle;
use App\Rules\ChapaSecretKey;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The gym's own Chapa account, which members' online payments go to: whether
 * it is connected, with a test or live key, and by whom. Owners connect,
 * replace or disconnect it; managers and accountants only see it. The key is
 * never shown, only its last 4 characters. See ADR 0009 and ADR 0029.
 */
class OnlinePayments extends Page
{
    use ShowsGymTime;
    use TranslatesItsTitle;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Money;

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Online payments';

    public static function canAccess(): bool
    {
        return Gate::allows('view', ChapaAccount::class);
    }

    public function content(Schema $schema): Schema
    {
        $account = self::account();

        return $schema->components([
            Section::make(__('Chapa'))
                ->description(__('Members\' online payments go straight to the gym\'s own Chapa account. The platform never holds the money.'))
                ->icon(Heroicon::OutlinedCreditCard)
                ->iconColor($account === null ? 'gray' : Badges::color($account->mode))
                ->columns(['default' => 1, 'md' => 4])
                ->schema($account === null ? [
                    TextEntry::make('connected')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->icon(Heroicon::OutlinedExclamationTriangle)
                        ->iconColor('warning')
                        ->state(__('Not connected. Connect the gym\'s Chapa account to send payment links and let members pay in the app.')),
                ] : [
                    TextEntry::make('mode')
                        ->label(__('Key'))
                        ->state($account->mode->value === 'live' ? __(':mode: real payments', ['mode' => Badges::label($account->mode)]) : __(':mode: test payments only', ['mode' => Badges::label($account->mode)]))
                        ->badge()
                        ->color(Badges::color($account->mode)),
                    TextEntry::make('key_hint')->label(__('Ends in'))->state('…'.$account->key_hint)->fontFamily('mono'),
                    TextEntry::make('connected_by')->label(__('Connected by'))->state($account->connectedBy?->user->name),
                    TextEntry::make('connected_at')->label(__('Connected'))->state($account->updated_at)->formatStateUsing(Dates::asDateTime(self::gymTimezone())),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label(fn (): string => self::account() === null ? __('Connect Chapa') : __('Replace key'))
                ->visible(fn (): bool => Gate::allows('connect', ChapaAccount::class))
                ->modalDescription(__('Copy the secret key from your Chapa dashboard, under Settings > API. It starts with CHASECK_TEST for test payments or CHASECK for real ones.'))
                ->schema([
                    TextInput::make('secret_key')
                        ->label(__('Secret key'))
                        ->password()
                        ->required()
                        ->maxLength(255)
                        ->rules(fn (): array => [self::acceptedByChapa()]),
                ])
                ->action(function (array $data): void {
                    ChapaAccount::connect(trim((string) $data['secret_key']), app(CurrentStaff::class)->get());

                    Notification::make()->success()->title(__('Chapa is connected.'))->send();
                }),
            Action::make('disconnect')->label(__('Disconnect'))
                ->color('danger')
                ->visible(fn (): bool => self::account() !== null && Gate::allows('disconnect', ChapaAccount::class))
                ->requiresConfirmation()
                ->modalDescription(__('Members can no longer pay online, and open payment links can no longer be checked. The key is deleted.'))
                ->action(function (): void {
                    ChapaAccount::query()->delete();

                    Notification::make()->success()->title(__('Chapa is disconnected.'))->send();
                }),
        ];
    }

    private static function account(): ?ChapaAccount
    {
        return ChapaAccount::query()->with('connectedBy.user')->first();
    }

    /**
     * The API's rule for the key; when Chapa doesn't answer, the key can't
     * be checked, so it isn't saved and the staff member tries again.
     */
    private static function acceptedByChapa(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                (new ChapaSecretKey)->validate($attribute, $value, $fail);
            } catch (ChapaRequestFailed $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
