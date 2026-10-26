<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Members;

use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Enums\Gender;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use App\Domains\Shared\PhoneNumber\Exceptions\InvalidPhoneNumber;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use App\Filament\Staff\Actions\CheckInAction;
use App\Filament\Staff\Resources\Members\Pages\CreateMember;
use App\Filament\Staff\Resources\Members\Pages\EditMember;
use App\Filament\Staff\Resources\Members\Pages\ListMembers;
use App\Filament\Staff\Resources\Members\Pages\ViewMember;
use App\Rules\ValidPhoneNumber;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * The gym's members: registering, finding, editing and archiving them, with
 * the API's rules. Archived members are left out, as in the API. See ADR 0017
 * and ADR 0018.
 */
class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'full_name';

    /**
     * The API's rules for a member (StoreMemberRequest): one member per phone
     * number in the gym, and a home branch the staff member works at.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Member')
                    ->columns(2)
                    ->schema([
                        TextInput::make('full_name')->required()->maxLength(255),
                        TextInput::make('phone')
                            ->tel()
                            ->required()
                            ->rules(fn (?Member $record): array => [new ValidPhoneNumber, self::phoneNotTakenInTheGym($record)]),
                        TextInput::make('email')->email()->maxLength(255),
                        Select::make('home_branch_id')
                            ->label('Home branch')
                            ->required()
                            ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all())
                            ->default(fn (): ?int => self::onlyBranchWorkedAt()),
                        Select::make('gender')->options([
                            Gender::Female->value => 'Female',
                            Gender::Male->value => 'Male',
                        ]),
                        DatePicker::make('date_of_birth')->beforeOrEqual('yesterday'),
                    ]),
                Section::make('Emergency contact')
                    ->columns(2)
                    ->schema([
                        TextInput::make('emergency_contact_name')->label('Name')->maxLength(255)->requiredWith('emergency_contact_phone'),
                        TextInput::make('emergency_contact_phone')
                            ->label('Phone')
                            ->tel()
                            ->requiredWith('emergency_contact_name')
                            ->rules([new ValidPhoneNumber]),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Member')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('full_name'),
                        TextEntry::make('phone'),
                        TextEntry::make('email')->placeholder('None'),
                        TextEntry::make('homeBranch.name')->label('Home branch'),
                        TextEntry::make('gender')->placeholder('Not given'),
                        TextEntry::make('date_of_birth')->date()->placeholder('Not given'),
                        TextEntry::make('created_at')->label('Registered')->date(),
                        TextEntry::make('balance_due')
                            ->label('Balance due')
                            ->state(fn (Member $record): string => self::balanceDueOf($record)->format()),
                    ]),
                Section::make('Emergency contact')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('emergency_contact_name')->label('Name')->placeholder('None'),
                        TextEntry::make('emergency_contact_phone')->label('Phone')->placeholder('None'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->defaultSort('full_name')
            ->defaultPaginationPageOption(25)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('homeBranch'))
            ->splitSearchTerms(false)
            ->columns([
                TextColumn::make('full_name')
                    ->label('Name')
                    ->sortable()
                    ->searchable(query: self::searchMembers(...)),
                TextColumn::make('phone'),
                TextColumn::make('homeBranch.name')->label('Home branch'),
                TextColumn::make('created_at')->label('Registered')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('home_branch_id')
                    ->label('Home branch')
                    ->relationship('homeBranch', 'name'),
            ])
            ->recordActions([
                CheckInAction::make(),
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'create' => CreateMember::route('/create'),
            'view' => ViewMember::route('/{record}'),
            'edit' => EditMember::route('/{record}/edit'),
        ];
    }

    /**
     * Fill the member from the form. The home branch isn't mass assignable,
     * so it is set through the relation, as the API does.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fillMember(Member $member, array $data): Member
    {
        $member->fill(Arr::except($data, 'home_branch_id'));
        $member->homeBranch()->associate(Branch::query()->findOrFail((int) $data['home_branch_id']));
        $member->save();

        return $member;
    }

    /**
     * The branch to preset when the staff member works at only one.
     */
    public static function onlyBranchWorkedAt(): ?int
    {
        $branches = app(CurrentStaff::class)->get()->branchesWorkedAt();

        return $branches->count() === 1 ? (int) $branches->first()?->getKey() : null;
    }

    /**
     * One member per phone number in the gym, compared in E.164 form however
     * it was typed. Archived members free their number.
     */
    private static function phoneNotTakenInTheGym(?Member $ignoring): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoring): void {
            try {
                $phone = PhoneNumber::of(is_string($value) ? $value : '')->e164;
            } catch (InvalidPhoneNumber) {
                return;
            }

            $taken = Member::query()
                ->where('phone', $phone)
                ->when($ignoring !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoring?->getKey()))
                ->exists();

            if ($taken) {
                $fail('Another member of this gym already has this phone number.');
            }
        };
    }

    /**
     * Search as the API does: part of a name, or the digits of a phone number.
     *
     * @param  Builder<Member>  $query
     * @return Builder<Member>
     */
    private static function searchMembers(Builder $query, string $search): Builder
    {
        return $query->search($search);
    }

    /**
     * What the member still owes across their memberships. It is looked up
     * here because the Members domain doesn't know about Billing.
     */
    private static function balanceDueOf(Member $member): Money
    {
        return Payment::balancesDueOf([$member->id])[$member->id] ?? Money::zero();
    }
}
