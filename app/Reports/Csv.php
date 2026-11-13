<?php

declare(strict_types=1);

namespace App\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes a report to a CSV download as it is read. The file starts with a
 * byte order mark, so spreadsheets read Amharic names as UTF-8, and a cell
 * that a spreadsheet would run as a formula is written as text. See ADR 0024.
 */
final class Csv
{
    public static function download(Report $report): StreamedResponse
    {
        return response()->streamDownload(function () use ($report): void {
            $file = fopen('php://output', 'w');

            if ($file === false) {
                return;
            }

            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $report->headings(), escape: '');

            foreach ($report->rows() as $row) {
                fputcsv($file, array_map(self::asText(...), $row), escape: '');
            }

            fclose($file);
        }, $report->filename(), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A cell starting with =, @ or a tab, or with + or - not followed by a
     * digit, could run as a formula, so it is written as text. Phone numbers
     * and amounts are left as they are.
     */
    private static function asText(string $cell): string
    {
        return preg_match('/^([=@\t\r]|[+-](?![0-9]))/', $cell) === 1 ? "'".$cell : $cell;
    }
}
