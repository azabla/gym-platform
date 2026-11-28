<?php

declare(strict_types=1);

namespace App\Filament\Staff\Support;

use App\Domains\Activity\Models\ActivityRecord;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * What was done with a record's money, newest first, at the foot of its page,
 * for those who see the money log: owners and managers (ADR 0046).
 */
final class ActivityHistory
{
    public static function section(): Section
    {
        return Section::make(__('History'))
            ->description(__('Who did what with its money, newest first.'))
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->collapsible()
            ->visible(fn (?Model $record): bool => $record?->exists === true && Gate::allows('viewAny', ActivityRecord::class))
            ->schema([
                TextEntry::make('activity_history')
                    ->label(__('History'))
                    ->hiddenLabel()
                    ->state(fn (?Model $record): array => $record === null ? [] : self::linesFor($record))
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->placeholder(__('Nothing recorded yet')),
            ]);
    }

    /**
     * "28 Nov 2026 10:05 · Selam · Receipt 12: 2,500.00 ETB by Cash …"
     *
     * @return list<string>
     */
    public static function linesFor(Model $record): array
    {
        $timezone = app(CurrentOrganization::class)->get()->timezone;
        $lines = [];

        foreach (ActivityRecord::query()->about($record)->with('staff.user')->limit(50)->get() as $activity) {
            $lines[] = implode(' · ', [
                Dates::short($activity->occurred_at->setTimezone($timezone)).' '.$activity->occurred_at->setTimezone($timezone)->format('H:i'),
                $activity->staff === null ? __('Online, or the platform') : $activity->staff->user->name,
                ActivityText::of($activity),
            ]);
        }

        return $lines;
    }
}
