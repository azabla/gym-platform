<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Domains\Identity\Models\User;
use App\Domains\Notifications\Enums\StaffNotificationTopic;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Language\Language;
use App\Filament\Staff\Support\Languages;
use App\Filament\Staff\Support\TranslatesItsTitle;
use App\Notifications\StaffNotification;
use App\Notifications\StaffRecipients;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The staff member's own messages, reached from the user menu: the language
 * they come in, at every gym (ADR 0034), and, at this gym, whether each topic
 * the staff member's role hears about also comes by push to the staff app
 * and by SMS (ADR 0035), and the panel itself.
 *
 * @property-read Schema $form
 */
class MyNotifications extends Page
{
    use TranslatesItsTitle;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'My notifications';

    /**
     * @var array{message_language?: string, topics?: array<string, array{push?: bool, sms?: bool}>}|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'message_language' => self::user()->messageLanguage()->value,
            'topics' => self::staff()->notificationChoices(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $topics = StaffRecipients::topicsFor(self::staff()->role);

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Language'))
                    ->description(__('The language of this panel, and of your inbox messages, pushes and texts, at every gym you work at.'))
                    ->icon(Heroicon::OutlinedLanguage)
                    ->schema([
                        Radio::make('message_language')
                            ->label(__('Message language'))
                            ->hiddenLabel()
                            ->options(Languages::options())
                            ->descriptions([
                                Language::English->value => 'Members to call: 3 members to call…',
                                Language::Amharic->value => 'የሚደወልላቸው አባላት፦ የሚደወልላቸው 3 አባላት አሉ…',
                            ])
                            ->required(),
                    ]),
                Section::make(__('At :gym', ['gym' => self::staff()->organization->name]))
                    ->description(self::howTheyReachYou())
                    ->icon(Heroicon::OutlinedBellAlert)
                    ->visible($topics !== [])
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema(array_map(fn (StaffNotificationTopic $topic): Fieldset => Fieldset::make(self::labelOf($topic))
                        ->columns(2)
                        ->schema([
                            Toggle::make("topics.{$topic->value}.push")
                                ->label(__('By push'))
                                ->onIcon(Heroicon::OutlinedDevicePhoneMobile)
                                ->helperText(self::describe($topic)),
                            Toggle::make("topics.{$topic->value}.sms")
                                ->label(__('By SMS'))
                                ->onIcon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                                ->disabled(self::user()->phone === null),
                        ]), $topics)),
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
                        Action::make('save')->label(__('Save'))->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $language = Language::from((string) ($state['message_language'] ?? Language::English->value));
        self::user()->update(['message_language' => $language]);

        $choices = [];

        foreach (StaffRecipients::topicsFor(self::staff()->role) as $topic) {
            $choices[$topic->value] = [
                'push' => (bool) ($state['topics'][$topic->value]['push'] ?? true),
                'sms' => (bool) ($state['topics'][$topic->value]['sms'] ?? false),
            ];
        }

        if ($choices !== []) {
            self::staff()->chooseNotifications($choices);
        }

        Notification::make()->success()->title(__('Saved. Your messages come in :language.', ['language' => Languages::label($language)]))->send();
    }

    private static function labelOf(StaffNotificationTopic $topic): string
    {
        return match ($topic) {
            StaffNotificationTopic::OnlinePayments => __('Paid online'),
            StaffNotificationTopic::Refunds => __('Refunds needed'),
            StaffNotificationTopic::AppFreezes => __('Freezes in the app'),
            StaffNotificationTopic::MembersToCall => __('Members to call'),
            StaffNotificationTopic::PlatformBill => __('Platform bill'),
        };
    }

    private static function describe(StaffNotificationTopic $topic): string
    {
        return match ($topic) {
            StaffNotificationTopic::OnlinePayments => __('A member paid with Chapa at your branches.'),
            StaffNotificationTopic::Refunds => __('Chapa took money that isn\'t due and the gym must give it back.'),
            StaffNotificationTopic::AppFreezes => __('A member froze their membership in the app, or came back early.'),
            StaffNotificationTopic::MembersToCall => __('Each morning from 08:00: memberships ending within 7 days.'),
            StaffNotificationTopic::PlatformBill => __('The platform\'s invoices to the gym, for owners: always by push and SMS.'),
        };
    }

    private static function howTheyReachYou(): string
    {
        $phone = self::user()->phone;
        $texts = $phone === null
            ? __('Texts need a phone number on your account.')
            : __('Texts go to your phone from :from to :until; at night they are skipped.', ['from' => sprintf('%02d:00', StaffNotification::TEXTS_FROM_HOUR), 'until' => sprintf('%02d:00', StaffNotification::TEXTS_UNTIL_HOUR)]);

        return __('Every message is in your Inbox. Pushes reach the staff app on your phone.').' '.$texts;
    }

    private static function staff(): Staff
    {
        return app(CurrentStaff::class)->get();
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
