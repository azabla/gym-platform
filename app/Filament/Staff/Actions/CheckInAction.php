<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Resources\CheckIns\CheckInResource;
use App\Filament\Staff\Resources\Members\MemberResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
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

        $this->label('Check in')
            ->visible(fn (): bool => Gate::allows('create', CheckIn::class))
            ->schema([
                Select::make('branch_id')
                    ->label('Branch')
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
                    Notification::make()->success()->title("{$record->full_name} is let in.")->send();

                    return;
                }

                Notification::make()
                    ->danger()
                    ->title("{$record->full_name} is turned away.")
                    ->body(CheckInResource::reasonLabel($checkIn->reason_code))
                    ->send();
            });
    }
}
