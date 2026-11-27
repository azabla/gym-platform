<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Filament\Staff\Support\Photos;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Check a member in at the desk, at a branch the staff member works at. The
 * door's rules decide (ADR 0011 and ADR 0016), and every attempt is kept, let
 * in or turned away with the reason. See ADR 0018.
 */
class CheckInAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'checkIn';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Check in'))
            ->icon(Heroicon::OutlinedArrowRightEndOnRectangle)
            ->modalIcon(Heroicon::OutlinedArrowRightEndOnRectangle)
            ->color('success')
            ->visible(fn (): bool => Gate::allows('create', CheckIn::class))
            ->schema([
                Select::make('branch_id')
                    ->label(__('Branch'))
                    ->required()
                    ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all())
                    ->default(MemberResource::onlyBranchWorkedAt()),
            ])
            ->action(function (array $data, Member $record): void {
                $checkIn = CheckIn::atDesk(
                    $record,
                    Branch::query()->findOrFail((int) $data['branch_id']),
                    app(CurrentStaff::class)->get(),
                );

                if ($checkIn->reason_code === null) {
                    Notification::make()
                        ->success()
                        ->title(__(':name is let in.', ['name' => $record->full_name]))
                        ->body(self::photoOf($record))
                        ->send();

                    return;
                }

                Notification::make()
                    ->danger()
                    ->title(__(':name is turned away.', ['name' => $record->full_name]))
                    ->body(__($checkIn->reason_code->label()))
                    ->send();
            });
    }

    /**
     * The member's photo, so the desk sees it is them (ADR 0036), or nothing
     * when they have none. Filament shows a notification's body as cleaned
     * HTML.
     */
    private static function photoOf(Member $member): ?string
    {
        if ($member->photo_path === null) {
            return null;
        }

        return sprintf(
            '<img src="%s" alt="%s" width="96" height="96">',
            e(Photos::urlFor($member)),
            e((string) $member->full_name),
        );
    }
}
