<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Domains\Identity\Enums\SignInApp;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\SignIns;
use App\Filament\Staff\Support\Tables;
use App\Filament\Staff\Support\TranslatesItsTitle;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The phones signed in as the staff member, in the staff app or the members
 * app, from the user menu (ADR 0046): each can be signed out, say when a
 * phone is lost, and all at once. A sign-in unused for 30 days (staff app)
 * or 90 (members app) ends by itself.
 */
class MyDevices extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesItsTitle;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'My devices';

    protected ?string $subheading = 'Phones signed in as you. Sign out one you lost or no longer use; a sign-in unused for 30 days in the staff app, or 90 in the members app, ends by itself.';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedDevicePhoneMobile, __('No phones signed in'), __('When you sign in to the staff app or the members app on a phone, it shows here.'))
            ->query(fn (): Builder => PersonalAccessToken::query()->where('tokenable_type', 'user')->where('tokenable_id', self::user()->getKey()))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('COALESCE(last_used_at, created_at) DESC'))
            ->columns([
                TextColumn::make('name')->label(__('Phone'))->weight('semibold'),
                TextColumn::make('app')
                    ->label(__('App'))
                    ->formatStateUsing(fn (?string $state): string => __((SignInApp::tryFrom((string) $state) ?? SignInApp::Staff)->label()))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('last_used_at')->label(__('Last used'))->since()->placeholder(__('Not yet')),
                TextColumn::make('created_at')->label(__('Signed in'))->since(),
            ])
            ->filters([
                SelectFilter::make('app')->label(__('App'))->native(false)
                    ->options([SignInApp::Staff->value => __(SignInApp::Staff->label()), SignInApp::Members->value => __(SignInApp::Members->label())]),
            ])
            ->recordActions([
                Action::make('signOut')
                    ->label(__('Sign out'))
                    ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalIcon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->modalHeading(fn (PersonalAccessToken $record): string => __('Sign out :phone?', ['phone' => $record->name]))
                    ->modalDescription(__('The phone has to sign in again. Its door QR code stops working.'))
                    ->action(function (PersonalAccessToken $record): void {
                        SignIns::end($record);

                        Notification::make()->success()->title(__('Signed out.'))->send();
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('signOutEverywhere')
                ->label(__('Sign out every phone'))
                ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('Sign out every phone?'))
                ->modalDescription(__('Every phone signed in as you, in either app, has to sign in again. This panel stays signed in.'))
                ->visible(fn (): bool => self::user()->tokens()->exists())
                ->action(function (): void {
                    $ended = 0;

                    foreach (SignIns::of(self::user()) as $token) {
                        SignIns::end($token);
                        $ended++;
                    }

                    Notification::make()->success()->title(trans_choice('{0} No phone was signed in.|{1} 1 phone signed out.|[2,*] :count phones signed out.', $ended))->send();
                }),
        ];
    }

    /**
     * The signed-in person as a plain User: the panel's PanelUser isn't the
     * owner of their sign-ins in the database.
     */
    private static function user(): User
    {
        return once(fn (): User => User::query()->findOrFail(auth()->id()));
    }
}
