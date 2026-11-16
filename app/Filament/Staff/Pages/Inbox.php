<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Resources\Concerns\ShowsGymTime;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Filament\Staff\Resources\Memberships\MembershipResource;
use App\Filament\Staff\Resources\PaymentLinks\PaymentLinkResource;
use App\Filament\Staff\Resources\Payments\PaymentResource;
use App\Notifications\MessageText;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The staff member's inbox at the gym: online payments, refunds needed,
 * freezes made in the app and the morning list (ADR 0027). Opening a message
 * marks it read and goes to what it is about; the menu shows how many are
 * unread.
 */
class Inbox extends Page implements HasTable
{
    use InteractsWithTable;
    use ShowsGymTime;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Inbox';

    public static function getNavigationBadge(): ?string
    {
        $unread = app(CurrentStaff::class)->get()->unreadNotifications()->count();

        return $unread > 0 ? (string) $unread : null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(CurrentStaff::class)->get()->notifications()->getQuery())
            ->defaultPaginationPageOption(25)
            ->columns([
                IconColumn::make('read_at')
                    ->label('')
                    ->state(fn (DatabaseNotification $record): bool => $record->read_at === null)
                    ->boolean()
                    ->trueIcon(Heroicon::Bell)
                    ->falseIcon(Heroicon::OutlinedCheck)
                    ->trueColor('warning')
                    ->falseColor('gray'),
                TextColumn::make('title')
                    ->weight(fn (DatabaseNotification $record): string => $record->read_at === null ? 'bold' : 'normal')
                    ->state(fn (DatabaseNotification $record): string => self::text($record, 'title'))
                    ->description(fn (DatabaseNotification $record): string => self::text($record, 'body')),
                TextColumn::make('created_at')->label('When')->since()->timezone(self::gymTimezone()),
            ])
            ->recordUrl(null)
            ->recordActions([
                Action::make('open')
                    ->action(function (DatabaseNotification $record, Action $action): void {
                        $record->markAsRead();

                        $action->redirect(self::urlOf($record));
                    }),
            ])
            ->emptyStateHeading('Nothing new')
            ->emptyStateDescription('Online payments, refunds to make, freezes made in the app and the morning list of members to call arrive here.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markAllRead')
                ->label('Mark all read')
                ->visible(fn (): bool => app(CurrentStaff::class)->get()->unreadNotifications()->exists())
                ->action(function (): void {
                    app(CurrentStaff::class)->get()->unreadNotifications()->update(['read_at' => now()]);

                    Notification::make()->success()->title('All read.')->send();
                }),
        ];
    }

    private static function text(DatabaseNotification $notification, string $part): string
    {
        return MessageText::forStaff($notification->type, $part, (array) ($notification->data['details'] ?? []));
    }

    /**
     * What the message is about: a payment, a payment link, a membership or
     * a member, or else the dashboard and its members to call.
     */
    private static function urlOf(DatabaseNotification $notification): string
    {
        /** @var array<string, string> $data */
        $data = (array) ($notification->data['data'] ?? []);

        return match (true) {
            isset($data['payment_id']) => PaymentResource::getUrl('view', ['record' => $data['payment_id']]),
            isset($data['payment_link_id']) => PaymentLinkResource::getUrl('view', ['record' => $data['payment_link_id']]),
            isset($data['membership_id']) => MembershipResource::getUrl('view', ['record' => $data['membership_id']]),
            isset($data['member_id']) => MemberResource::getUrl('view', ['record' => $data['member_id']]),
            default => Dashboard::getUrl(),
        };
    }
}
