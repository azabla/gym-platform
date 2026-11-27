<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Language\Language;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Support\Languages;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The gym's own settings: its name, address in the panel and time zone, and
 * the language its members get texts in unless they chose their own. Every
 * staff member sees them; owners change the message language. See ADR 0034.
 */
class GymSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::GymSetup;

    protected static ?int $navigationSort = 7;

    protected static ?string $title = 'Gym settings';

    public static function canAccess(): bool
    {
        return Gate::allows('view', self::gym());
    }

    public function content(Schema $schema): Schema
    {
        $gym = self::gym();

        return $schema->components([
            Section::make(__('The gym'))
                ->description(__('Set when the gym joined the platform. Ask the platform to change them.'))
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('name')->label(__('Name'))->state($gym->name)->weight('bold'),
                    TextEntry::make('slug')->label(__('Address in the panel'))->state('/staff/'.$gym->slug)->fontFamily('mono')->copyable(),
                    TextEntry::make('timezone')->label(__('Time zone'))->state($gym->timezone)->icon(Heroicon::OutlinedClock),
                ]),
            Section::make(__('Messages'))
                ->description(__('The language members get texts in, unless the desk or the members app chose another for them. Pushes and the app\'s inbox follow the language of each member\'s phone.'))
                ->icon(Heroicon::OutlinedLanguage)
                ->schema([
                    TextEntry::make('message_language')
                        ->label(__('Message language'))
                        ->state(Languages::label($gym->messageLanguage()))
                        ->badge()
                        ->color('primary'),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('changeMessageLanguage')
                ->label(__('Change message language'))
                ->icon(Heroicon::OutlinedLanguage)
                ->visible(fn (): bool => Gate::allows('update', self::gym()))
                ->modalIcon(Heroicon::OutlinedLanguage)
                ->modalWidth(Width::Medium)
                ->modalDescription(fn (): string => __('Members now get texts in :language. Members who chose their own language keep it.', ['language' => Languages::label(self::gym()->messageLanguage())]))
                ->fillForm(fn (): array => ['message_language' => self::gym()->messageLanguage()->value])
                ->schema([
                    Select::make('message_language')
                        ->label(__('Message language'))
                        ->options(Languages::options())
                        ->native(false)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $language = Language::from((string) $data['message_language']);
                    self::gym()->update(['message_language' => $language]);

                    Notification::make()->success()->title(__('Members now get texts in :language.', ['language' => Languages::label($language)]))->send();
                }),
        ];
    }

    private static function gym(): Organization
    {
        return app(CurrentOrganization::class)->get();
    }
}
