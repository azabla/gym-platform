<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Staff;

use App\Domains\Identity\Enums\SignInApp;
use App\Domains\Identity\Models\PasswordLink;
use App\Domains\Identity\SignIns;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Exceptions\StaffRefused;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Actions\Refusals;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\Staff\Pages\CreateStaff;
use App\Filament\Staff\Resources\Staff\Pages\EditStaff;
use App\Filament\Staff\Resources\Staff\Pages\ListStaff;
use App\Filament\Staff\Support\Badges;
use App\Filament\Staff\Support\Dates;
use App\Filament\Staff\Support\Tables;
use App\Filament\Staff\Support\TranslatesItsLabels;
use App\Rules\ValidPhoneNumber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * The gym's staff, for owners and managers, with the API's rules: owners
 * manage everyone, managers the roles below them at their own branches, and
 * no one changes or archives themselves. A manager limited to some branches
 * sees only staff at those branches. See ADR 0019.
 */
class StaffResource extends Resource
{
    use TranslatesItsLabels;

    protected static ?string $model = Staff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::GymSetup;

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'staff member';

    protected static ?string $pluralModelLabel = 'staff';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Who they are'))
                    ->description(__('They sign in to this panel with their phone number, and get an SMS with a link to set their password.'))
                    ->icon(Heroicon::OutlinedUser)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label(__('Name'))
                            ->placeholder(__('Meron Assefa'))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->maxLength(255)
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                        TextInput::make('phone')->label(__('Phone'))
                            ->tel()
                            ->placeholder('0911 22 33 44')
                            ->prefixIcon(Heroicon::OutlinedPhone)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->rules([new ValidPhoneNumber])
                            ->helperText(fn (string $operation): string => $operation === 'edit'
                                ? __('The name and phone belong to their account and can\'t be changed here.')
                                : __('They sign in with this number.'))
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                    ]),
                Section::make(__('What they can do'))
                    ->icon(Heroicon::OutlinedKey)
                    ->columns(2)
                    ->schema([
                        Select::make('role')->label(__('Role'))
                            ->required()
                            ->native(false)
                            ->options(fn (): array => self::rolesTheStaffMemberManages())
                            ->live()
                            ->helperText(fn (Get $get): string => self::whatTheRoleDoes(StaffRole::tryFrom((string) $get('role')))),
                        Select::make('branch_ids')
                            ->label(__('Branches'))
                            ->multiple()
                            ->native(false)
                            ->placeholder(__('All branches'))
                            ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all())
                            ->helperText(__('Leave empty for all branches. Owners always work at all branches.')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedBriefcase, __('No staff here'), __('Add the people who work at the gym; each gets an SMS to set a password.'))
            ->defaultSort('id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'branches']))
            ->searchPlaceholder(__('Name'))
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('Name'))
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Staff $record): string => (string) $record->user->phone)
                    ->searchable(),
                Tables::badge('role'),
                TextColumn::make('branches_list')
                    ->label(__('Branches'))
                    ->badge()
                    ->color(fn (Staff $record): string => $record->works_at_all_branches ? 'primary' : 'gray')
                    ->state(fn (Staff $record): array => $record->works_at_all_branches ? [__('All branches')] : $record->branches->sortBy('name')->pluck('name')->all()),
                TextColumn::make('invite')
                    ->label(__('Password'))
                    ->badge()
                    ->state(fn (Staff $record): string => $record->user->password === null ? 'Invite pending' : 'Set')
                    ->color(fn (string $state): string => $state === 'Set' ? 'success' : 'warning')
                    ->icon(fn (string $state): Heroicon => $state === 'Set' ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedEnvelope)
                    ->formatStateUsing(fn (string $state): string => __($state)),
                TextColumn::make('deleted_at')->label(__('Archived'))->formatStateUsing(Dates::asDate())->badge()->color('gray')->placeholder(__('No'))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role')->label(__('Role'))->native(false)->options(Badges::options(StaffRole::class)),
                SelectFilter::make('branch')->label(__('Branch'))
                    ->native(false)
                    ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where(fn (Builder $query): Builder => $query
                            ->where('works_at_all_branches', true)
                            ->orWhereHas('branches', fn (Builder $branches): Builder => $branches->whereKey((int) $data['value'])))
                        : $query),
                TernaryFilter::make('invite_pending')
                    ->label(__('Password'))
                    ->native(false)
                    ->placeholder(__('Set or not'))
                    ->trueLabel(__('Invite pending'))
                    ->falseLabel(__('Password set'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereRelation('user', fn (Builder $user): Builder => $user->whereNull('password')),
                        false: fn (Builder $query): Builder => $query->whereRelation('user', fn (Builder $user): Builder => $user->whereNotNull('password')),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                TrashedFilter::make()->label(__('Archived'))->native(false),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
            'create' => CreateStaff::route('/create'),
            'edit' => EditStaff::route('/{record}/edit'),
        ];
    }

    /**
     * Archived staff can be found and restored, and a manager limited to
     * some branches sees only staff at those branches.
     *
     * @return Builder<Staff>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Staff> $query */
        $query = parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);

        return $query->atAnyOfBranches(app(CurrentStaff::class)->get()->limitedToBranchIds());
    }

    /**
     * Refuse a role or branches the signed-in staff member may not give, as
     * the API does, on the form's fields.
     *
     * @param  list<Branch>  $branches
     *
     * @throws ValidationException
     */
    public static function refuseWhatMayNotBeGiven(StaffRole $role, array $branches): void
    {
        $actor = app(CurrentStaff::class)->get();

        if (! $role->isManagedBy($actor->role)) {
            throw ValidationException::withMessages(['data.role' => __('You can\'t give someone this role.')]);
        }

        if (! $actor->mayAssign($role, ...$branches)) {
            throw ValidationException::withMessages(['data.branch_ids' => __('Choose only branches you work at.')]);
        }
    }

    /**
     * The branches chosen on the form, or none for all branches.
     *
     * @param  array<string, mixed>  $data
     * @return list<Branch>
     */
    public static function branchesIn(array $data): array
    {
        $branchIds = array_map('intval', (array) ($data['branch_ids'] ?? []));

        if ($branchIds === []) {
            return [];
        }

        return array_values(Branch::query()->whereKey($branchIds)->orderBy('name')->get()->all());
    }

    /**
     * Show a refusal on the form's field it belongs to.
     *
     * @throws ValidationException
     */
    public static function refusalOnTheForm(StaffRefused $refusal): ValidationException
    {
        return ValidationException::withMessages(['data.'.$refusal->field => $refusal->getMessage()]);
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')->label(__('Archive'))
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Archive this staff member?'))
            ->modalDescription(__('They can no longer sign in to this gym. What they recorded still shows their name, and they can be restored.'))
            ->visible(fn (Staff $record): bool => ! $record->trashed() && Gate::allows('delete', $record))
            ->action(function (Staff $record, Action $action): void {
                try {
                    $record->archive();
                } catch (StaffRefused $refusal) {
                    Refusals::halt($action, $refusal);
                }

                Notification::make()->success()->title(__('Staff member archived.'))->send();
            });
    }

    public static function restoreAction(): Action
    {
        return Action::make('restore')->label(__('Restore'))
            ->requiresConfirmation()
            ->visible(fn (Staff $record): bool => $record->trashed() && Gate::allows('restore', $record))
            ->action(function (Staff $record): void {
                $record->restore();

                Notification::make()->success()->title(__('Staff member restored.'))->send();
            });
    }

    /**
     * Sign the staff member out of the staff app on every phone, such as when
     * one is lost or they leave (ADR 0046). Their members-app sign-ins, as a
     * member of any gym, are theirs and stay.
     */
    public static function signOutEverywhereAction(): Action
    {
        return Action::make('signOutEverywhere')
            ->label(__('Sign out of the staff app'))
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->color('danger')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedDevicePhoneMobile)
            ->modalHeading(fn (Staff $record): string => __('Sign :name out of the staff app?', ['name' => $record->user->name]))
            ->modalDescription(__('Every phone signed in to the staff app as them has to sign in again, say when a phone is lost. Their members app stays signed in.'))
            ->visible(fn (Staff $record): bool => Gate::allows('update', $record) && $record->user->tokens()->where('app', SignInApp::Staff->value)->exists())
            ->action(function (Staff $record): void {
                $ended = SignIns::endEverywhere($record->user, SignInApp::Staff);

                Notification::make()->success()->title(trans_choice('{0} No phone was signed in.|{1} 1 phone signed out.|[2,*] :count phones signed out.', $ended))->send();
            });
    }

    public static function resendInviteAction(): Action
    {
        return Action::make('resendInvite')
            ->label(__('Send invite again'))
            ->requiresConfirmation()
            ->modalDescription(__('They get a new link by SMS to set their password. Earlier links stop working.'))
            ->visible(fn (Staff $record): bool => ! $record->trashed() && $record->user->password === null && Gate::allows('update', $record))
            ->action(function (Staff $record): void {
                PasswordLink::invite($record->user, app(CurrentStaff::class)->get()->organization->name);

                Notification::make()->success()->title(__('Invite sent by SMS.'))->send();
            });
    }

    /**
     * The roles the signed-in staff member may give, by value.
     *
     * @return array<string, string>
     */
    private static function rolesTheStaffMemberManages(): array
    {
        $actor = app(CurrentStaff::class)->get();
        $roles = [];

        foreach (StaffRole::cases() as $role) {
            if ($role->isManagedBy($actor->role)) {
                $roles[$role->value] = Str::headline($role->name);
            }
        }

        return $roles;
    }

    /**
     * What a role may do in the panel, so the right one is chosen.
     */
    private static function whatTheRoleDoes(?StaffRole $role): string
    {
        return match ($role) {
            StaffRole::Owner => __('Everything, including Chapa and other owners.'),
            StaffRole::Manager => __('Runs the gym: plans, offers, staff and money, but not Chapa.'),
            StaffRole::Accountant => __('Sees and handles the money and the reports.'),
            StaffRole::Receptionist => __('Works the desk: members, sales, payments and check-ins.'),
            StaffRole::Trainer => __('Checks members in and sees who came.'),
            null => __('Choose what they do at the gym.'),
        };
    }
}
