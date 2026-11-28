<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Memberships\Models\MemberDiscount;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Support\Dates;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;

/**
 * Put the member in a discount group, optionally until a day, or take them
 * out of theirs, with the API's rules: owners and managers, a group of this
 * gym, and a last day not in the past. See ADR 0032.
 */
class DiscountGroupAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'discountGroup';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Discount group'))
            ->icon('heroicon-o-user-group')
            ->visible(fn (): bool => Gate::allows('assign', DiscountGroup::class))
            ->fillForm(function (Member $record): array {
                $memberDiscount = MemberDiscount::of($record);

                return [
                    'discount_group_id' => $memberDiscount?->discount_group_id,
                    'until' => $memberDiscount?->until?->toDateString(),
                    'remove' => false,
                ];
            })
            ->schema([
                Select::make('discount_group_id')
                    ->label(__('Group'))
                    ->options(fn (): array => DiscountGroup::query()->orderBy('name')->get()
                        ->mapWithKeys(fn (DiscountGroup $group): array => [$group->id => __(':group (:discount off)', ['group' => $group->name, 'discount' => $group->discount])])
                        ->all())
                    ->required(fn (Get $get): bool => ! $get('remove'))
                    ->hidden(fn (Get $get): bool => (bool) $get('remove')),
                DatePicker::make('until')->hint(Dates::ethiopianHint())->live()
                    ->label(__('Until'))
                    ->helperText(__('Optional, such as when a student card expires. Leave empty for no end.'))
                    ->minDate(fn (): string => app(CurrentOrganization::class)->get()->today()->toDateString())
                    ->hidden(fn (Get $get): bool => (bool) $get('remove')),
                Toggle::make('remove')
                    ->label(__('Take them out of their group'))
                    ->live()
                    ->visible(fn (Member $record): bool => MemberDiscount::of($record) !== null),
            ])
            ->action(function (array $data, Member $record): void {
                if ((bool) ($data['remove'] ?? false)) {
                    MemberDiscount::remove($record, app(CurrentStaff::class)->get());

                    Notification::make()->success()->title(__('Taken out of their discount group.'))->send();

                    return;
                }

                $group = DiscountGroup::query()->findOrFail((int) $data['discount_group_id']);
                $until = filled($data['until'] ?? null) ? CarbonImmutable::parse((string) $data['until']) : null;
                MemberDiscount::assign($record, $group, $until, app(CurrentStaff::class)->get());

                Notification::make()->success()->title(__('In the :group group.', ['group' => $group->name]))->send();
            });
    }
}
