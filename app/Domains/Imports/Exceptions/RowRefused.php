<?php

declare(strict_types=1);

namespace App\Domains\Imports\Exceptions;

use DomainException;

/**
 * Why a row of a member import can't be imported. The row keeps the English
 * text and its values, and the staff panel shows __($text, $values) in each
 * reader's language; getMessage() is the English. See ADR 0044.
 */
final class RowRefused extends DomainException
{
    /**
     * @param  array<string, string>  $values
     */
    private function __construct(public readonly string $text, public readonly array $values = [], public readonly bool $isDuplicate = false)
    {
        $placeholders = array_map(fn (string $name): string => ":{$name}", array_keys($values));

        parent::__construct(strtr($text, array_combine($placeholders, $values)));
    }

    public static function missing(string $column): self
    {
        return new self('The :column is missing.', ['column' => str_replace('_', ' ', $column)]);
    }

    public static function badPhone(string $phone): self
    {
        return preg_match('/^\d+(\.\d+)?E\+\d+$/i', trim($phone)) === 1
            ? new self(':phone isn\'t a phone number: the spreadsheet turned it into a number. Format the column as text and type it again.', ['phone' => $phone])
            : new self(':phone isn\'t a phone number like 0911 234 567.', ['phone' => $phone]);
    }

    public static function badDate(string $column, string $date): self
    {
        return new self('The :column :date isn\'t a date like 2026-11-27 or 27/11/2026.', ['column' => str_replace('_', ' ', $column), 'date' => $date]);
    }

    public static function badGender(string $gender): self
    {
        return new self('The gender :gender isn\'t female or male.', ['gender' => $gender]);
    }

    public static function badAmount(string $amount): self
    {
        return new self('The amount paid :amount isn\'t an amount like 2500 or 1,500.50.', ['amount' => $amount]);
    }

    public static function unknownBranch(string $branch): self
    {
        return new self('There is no branch :branch. Use a branch\'s name or code.', ['branch' => $branch]);
    }

    public static function unknownPlan(string $plan): self
    {
        return new self('There is no plan :plan on sale.', ['plan' => $plan]);
    }

    public static function planNotSoldAt(string $plan, string $branch): self
    {
        return new self('The plan :plan isn\'t sold at :branch.', ['plan' => $plan, 'branch' => $branch]);
    }

    public static function membershipWithoutPlan(): self
    {
        return new self('A membership needs its plan.');
    }

    public static function membershipWithoutStart(): self
    {
        return new self('A membership needs its first day (starts on).');
    }

    public static function endsBeforeItStarts(): self
    {
        return new self('A membership can\'t end before its first day.');
    }

    public static function moreThanThePrice(string $amount, string $price): self
    {
        return new self('The amount paid :amount is more than the plan\'s price, :price.', ['amount' => $amount, 'price' => $price]);
    }

    public static function refused(string $reason): self
    {
        return new self(':reason', ['reason' => $reason]);
    }

    public static function alreadyAMember(string $name): self
    {
        return new self('Already a member: :name has this phone.', ['name' => $name], isDuplicate: true);
    }

    public static function archivedMember(string $name): self
    {
        return new self(':name has this phone but is archived: restore them instead.', ['name' => $name], isDuplicate: true);
    }

    public static function sameAsLine(int $line): self
    {
        return new self('The same phone as line :line.', ['line' => (string) $line], isDuplicate: true);
    }
}
