<?php

declare(strict_types=1);

namespace App\Domains\Imports\Csv;

use App\Domains\Imports\Exceptions\FileRefused;

/**
 * A member import file read: CSV saved as UTF-8 (a byte-order mark is fine),
 * separated by commas or, as some spreadsheets save it, semicolons. The first
 * line names the columns, matched however they are capitalised or spaced.
 * See ADR 0044.
 */
final class MemberCsv
{
    public const MAX_ROWS = 2000;

    public const REQUIRED = ['full_name', 'phone', 'home_branch'];

    public const COLUMNS = ['full_name', 'phone', 'gender', 'date_of_birth', 'home_branch', 'plan', 'starts_on', 'ends_on', 'amount_paid'];

    /**
     * The rows, each its line number in the file and its cells by column
     * name; blank lines are left out.
     *
     * @return array<int, array<string, string>>
     *
     * @throws FileRefused when the file can't be read as a member import
     */
    public static function rows(string $contents): array
    {
        $contents = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw FileRefused::notUtf8();
        }

        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $headerLine = (string) array_shift($lines);
        $separator = substr_count($headerLine, ';') > substr_count($headerLine, ',') ? ';' : ',';
        $headers = array_map(self::columnName(...), str_getcsv($headerLine, $separator, '"', ''));

        $missing = array_values(array_diff(self::REQUIRED, $headers));

        if ($missing !== []) {
            throw FileRefused::missingColumns($missing);
        }

        $rows = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '' || trim(str_replace($separator, '', $line)) === '') {
                continue;
            }

            $cells = str_getcsv($line, $separator, '"', '');
            $row = [];

            foreach ($headers as $position => $column) {
                if (in_array($column, self::COLUMNS, true)) {
                    $row[$column] = trim((string) ($cells[$position] ?? ''));
                }
            }

            $rows[$index + 2] = $row;
        }

        if ($rows === []) {
            throw FileRefused::empty();
        }

        if (count($rows) > self::MAX_ROWS) {
            throw FileRefused::tooBig(self::MAX_ROWS);
        }

        return $rows;
    }

    /**
     * The template staff fill in: the header and one example row.
     */
    public static function template(): string
    {
        return implode(',', self::COLUMNS)."\n"
            ."Hana Girma,0911 22 33 44,female,1995-04-12,Main Branch,Monthly,2026-11-01,2026-11-30,1500\n";
    }

    /**
     * "Full name", "full-name" and " FULL_NAME " all name full_name.
     */
    private static function columnName(string $header): string
    {
        return (string) preg_replace('/[\s\-]+/', '_', strtolower(trim($header)));
    }
}
