<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\BranchClosures\Pages;

use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Exceptions\BranchClosureRefused;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\BranchClosure;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\EthiopianCalendar\EthiopianDate;
use App\Filament\Staff\Actions\Refusals;
use App\Filament\Staff\Resources\BranchClosures\BranchClosureResource;
use App\Filament\Staff\Support\Dates;
use App\Listeners\TellTheMember;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ListBranchClosures extends ListRecords
{
    protected static string $resource = BranchClosureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addClosure')
                ->label(__('Add closure'))
                ->icon(Heroicon::OutlinedPlus)
                ->modalIcon(Heroicon::OutlinedCalendarDays)
                ->modalWidth(Width::TwoExtraLarge)
                ->modalDescription(__('Check-ins those days follow the closure, not the weekly hours. Members of the branches are told by push when it starts within 14 days.'))
                ->visible(fn (): bool => BranchClosureResource::branchesTheyMayClose() !== [])
                ->schema([
                    Section::make(__('Where and when'))
                        ->icon(Heroicon::OutlinedBuildingStorefront)
                        ->columns(2)
                        ->schema([
                            CheckboxList::make('branch_ids')
                                ->label(__('Branches'))
                                ->options(fn (): array => BranchClosureResource::branchesTheyMayClose())
                                ->default(fn (): array => array_keys(BranchClosureResource::branchesTheyMayClose()))
                                ->bulkToggleable()
                                ->columns(2)
                                ->columnSpanFull()
                                ->required()
                                ->live(),
                            DatePicker::make('starts_on')->hint(Dates::ethiopianHint())->live()
                                ->label(__('From'))
                                ->native(false)
                                ->displayFormat('D j M Y')
                                ->minDate(fn (): string => self::today()->toDateString())
                                ->required()
                                ->live(),
                            DatePicker::make('ends_on')->hint(Dates::ethiopianHint())->live()
                                ->label(__('To'))
                                ->native(false)
                                ->displayFormat('D j M Y')
                                ->afterOrEqual('starts_on')
                                ->helperText(__('The same day for a single day.'))
                                ->required()
                                ->live(),
                            TextInput::make('reason')->label(__('Reason'))
                                ->placeholder(__('Timket'))
                                ->helperText(__('Members see it, so keep it short.'))
                                ->maxLength(100)
                                ->required()
                                ->columnSpanFull()
                                ->live(onBlur: true),
                            Toggle::make('repeats_yearly')
                                ->label(__('Every year, on the same Ethiopian date'))
                                ->helperText(__('For Genna, Timket, Meskel and other fixed holidays. Fasika and Eid move, so add those each year.'))
                                ->columnSpanFull()
                                ->live(),
                        ]),
                    Section::make(__('Hours'))
                        ->icon(Heroicon::OutlinedClock)
                        ->columns(2)
                        ->schema([
                            Toggle::make('special_hours')
                                ->label(__('Open other hours instead of closed all day'))
                                ->columnSpanFull()
                                ->live(),
                            TimePicker::make('opens_at')
                                ->label(__('Opens'))
                                ->seconds(false)
                                ->prefixIcon(Heroicon::OutlinedSun)
                                ->visible(fn (Get $get): bool => (bool) $get('special_hours'))
                                ->required(fn (Get $get): bool => (bool) $get('special_hours'))
                                ->live(),
                            TimePicker::make('closes_at')
                                ->label(__('Closes'))
                                ->seconds(false)
                                ->prefixIcon(Heroicon::OutlinedMoon)
                                ->different('opens_at')
                                ->helperText(__('Earlier than opening means after midnight.'))
                                ->visible(fn (Get $get): bool => (bool) $get('special_hours'))
                                ->required(fn (Get $get): bool => (bool) $get('special_hours'))
                                ->live(),
                        ]),
                    TextEntry::make('preview')
                        ->hiddenLabel()
                        ->icon(Heroicon::OutlinedEye)
                        ->state(fn (Get $get): string => self::previewOf($get)),
                ])
                ->action(function (array $data, Action $action): void {
                    $branches = Branch::query()->whereKey(array_map('intval', (array) $data['branch_ids']))->orderBy('name')->get()->all();
                    $specialHours = (bool) ($data['special_hours'] ?? false);

                    try {
                        $closures = BranchClosure::schedule(
                            app(CurrentStaff::class)->get(),
                            $branches,
                            CarbonImmutable::parse((string) $data['starts_on']),
                            CarbonImmutable::parse((string) $data['ends_on']),
                            (string) $data['reason'],
                            $specialHours ? (string) $data['opens_at'] : null,
                            $specialHours ? (string) $data['closes_at'] : null,
                            (bool) ($data['repeats_yearly'] ?? false),
                        );
                    } catch (BranchClosureRefused $refusal) {
                        Refusals::halt($action, $refusal);
                    }

                    Notification::make()->success()->title(trans_choice('{1} Closure added.|[2,*] :count closures added.', $closures->count()))->send();
                }),
        ];
    }

    /**
     * A line saying what the closure will do, and who will be told.
     */
    private static function previewOf(Get $get): string
    {
        $branchIds = array_map('intval', (array) $get('branch_ids'));
        $startsOn = $get('starts_on');
        $endsOn = $get('ends_on');

        if ($branchIds === [] || ! is_string($startsOn) || $startsOn === '' || ! is_string($endsOn) || $endsOn === '') {
            return __('Choose the branches and the days to see what will happen.');
        }

        $from = CarbonImmutable::parse($startsOn);
        $to = CarbonImmutable::parse($endsOn);
        $names = Branch::query()->whereKey($branchIds)->orderBy('name')->pluck('name')->map(fn (mixed $name): string => (string) $name)->all();
        $days = $from->equalTo($to) ? Dates::dayOfWeek($from) : Dates::dayOfWeek($from).' – '.Dates::dayOfWeek($to);
        $what = $get('special_hours') && is_string($get('opens_at')) && is_string($get('closes_at'))
            ? __(':branches opens only :hours on :days.', [
                'branches' => implode(', ', $names),
                'hours' => substr($get('opens_at'), 0, 5).'–'.substr($get('closes_at'), 0, 5),
                'days' => $days,
            ])
            : __(':branches is closed on :days.', ['branches' => implode(', ', $names), 'days' => $days]);

        $told = $from->lte(self::today()->addDays(TellTheMember::TELL_CLOSURES_DAYS_AHEAD))
            ? trans_choice('{0} No members to tell.|{1} 1 member will get a push.|[2,*] :count members will get a push.', Member::query()->homeAtAnyOf($branchIds)->count())
            : __('It is more than 14 days away, so members aren\'t told now; they see it in the app.');

        $yearly = $get('repeats_yearly')
            ? ' '.__('Repeats every year on :date.', ['date' => EthiopianDate::fromGregorian($from->toDateString())->dayAndMonthIn(app()->getLocale())])
            : '';

        return "{$what}{$yearly} {$told}";
    }

    private static function today(): CarbonImmutable
    {
        return app(CurrentStaff::class)->get()->organization->today();
    }
}
