<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

/**
 * Freeze the membership for some days, with the API's rules: from today
 * unless an owner or manager backdates it, within the freeze days the plan
 * allowed unless an owner or manager goes beyond them. See ADR 0007 and
 * ADR 0018.
 */
class FreezeMembershipAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'freeze';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Freeze')
            ->visible(fn (Membership $record): bool => Gate::allows('freeze', $record))
            ->schema([
                DatePicker::make('starts_on')
                    ->label('First day')
                    ->required()
                    ->default(fn (): string => self::today()->toDateString())
                    ->rules(fn (): array => [self::notInThePastUnlessAllowed()]),
                DatePicker::make('ends_on')->label('Last day')->required(),
                Textarea::make('reason')->required()->maxLength(500),
            ])
            ->action(function (array $data, Membership $record, Action $action): void {
                $firstDay = CarbonImmutable::parse((string) $data['starts_on']);
                $lastDay = CarbonImmutable::parse((string) $data['ends_on']);
                $refusal = $record->refusalToFreeze($firstDay, $lastDay, Gate::allows('freezeBeyondAllowance', Membership::class));

                if ($refusal !== null) {
                    Refusals::halt($action, $refusal);
                }

                $record->freeze($firstDay, $lastDay, (string) $data['reason'], app(CurrentStaff::class)->get());

                Notification::make()->success()->title("Frozen. It now ends on {$record->refresh()->ends_on->format('j F Y')}.")->send();
            });
    }

    private static function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }

    private static function notInThePastUnlessAllowed(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (CarbonImmutable::parse((string) $value)->lessThan(self::today()) && Gate::denies('backdate', Membership::class)) {
                $fail('Only owners and managers can start a freeze on a past date.');
            }
        };
    }
}
