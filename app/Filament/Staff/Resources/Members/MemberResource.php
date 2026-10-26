<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Members;

use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\Members\Pages\ListMembers;
use App\Filament\Staff\Resources\Members\Pages\ViewMember;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The gym's members, to look up: registering and changing them stays in the
 * app for now. Archived members are left out, as in the API. See ADR 0017.
 */
class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'full_name';

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
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'view' => ViewMember::route('/{record}'),
        ];
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
