<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

/**
 * End a freeze early, when the member is back: a freeze going on today ends
 * yesterday, one still to come is dropped, and the membership's end date moves
 * back by the days given up. See ADR 0007 and ADR 0018.
 */
class EndFreezeAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'endFreeze';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('End freeze')
            ->visible(fn (Membership $record): bool => Gate::allows('freeze', $record) && self::freezesNotOver($record) !== [])
            ->schema(fn (Membership $record): array => [
                Select::make('freeze_id')
                    ->label('Freeze')
                    ->options(self::freezesNotOver($record))
                    ->default(array_key_first(self::freezesNotOver($record)))
                    ->required(),
            ])
            ->action(function (array $data, Membership $record, Action $action): void {
                $freeze = $record->freezes()->findOrFail((int) $data['freeze_id']);
                $today = app(CurrentOrganization::class)->get()->today();
                $refusal = $record->refusalToEndFreeze($freeze, $today);

                if ($refusal !== null) {
                    Refusals::halt($action, $refusal);
                }

                $record->endFreeze($freeze, $today);

                Notification::make()->success()->title("Freeze ended. It now ends on {$record->refresh()->ends_on->format('j F Y')}.")->send();
            });
    }

    /**
     * The freezes going on today or still to come, to choose from.
     *
     * @return array<int, string>
     */
    private static function freezesNotOver(Membership $membership): array
    {
        $today = app(CurrentOrganization::class)->get()->today()->toDateString();

        return $membership->freezes()
            ->where('ends_on', '>=', $today)
            ->orderBy('starts_on')
            ->get()
            ->mapWithKeys(fn (MembershipFreeze $freeze): array => [
                $freeze->id => "{$freeze->starts_on->format('j M Y')} to {$freeze->ends_on->format('j M Y')}",
            ])
            ->all();
    }
}
