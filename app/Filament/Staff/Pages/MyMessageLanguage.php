<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Language\Language;
use App\Filament\Staff\Support\Languages;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The language of the staff member's own messages: their inbox here and in
 * the API, and their texts. The panel itself stays in English. Reached from
 * the user menu. See ADR 0034.
 *
 * @property-read Schema $form
 */
class MyMessageLanguage extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'My message language';

    /**
     * @var array{message_language?: string}|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['message_language' => self::user()->messageLanguage()->value]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('My messages')
                    ->description('The language of your inbox messages and the texts sent to you, at every gym you work at. The panel itself stays in English.')
                    ->icon(Heroicon::OutlinedLanguage)
                    ->schema([
                        Radio::make('message_language')
                            ->hiddenLabel()
                            ->options(Languages::options())
                            ->descriptions([
                                Language::English->value => 'Members to call: 3 members to call…',
                                Language::Amharic->value => 'የሚደወልላቸው አባላት፦ የሚደወልላቸው 3 አባላት አሉ…',
                            ])
                            ->required(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save')->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $language = Language::from((string) ($this->form->getState()['message_language'] ?? Language::English->value));
        self::user()->update(['message_language' => $language]);

        Notification::make()->success()->title('Your messages now come in '.Languages::label($language).'.')->send();
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
