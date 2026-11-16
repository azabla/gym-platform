<?php

declare(strict_types=1);

namespace App\Reports;

use App\Domains\Organizations\Models\Organization;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * A report of the gym's records, as rows of text for a CSV file. Rows are
 * read in chunks as they are written, so a year of records never sits in
 * memory at once. See ADR 0024.
 */
abstract class Report
{
    /** How many records are read at a time. */
    protected const CHUNK = 500;

    /**
     * The name of the file, without the gym or the dates: payments.
     */
    abstract public function name(): string;

    /**
     * The column headings.
     *
     * @return list<string>
     */
    abstract public function headings(): array;

    /**
     * The rows, one list of cells per record, in the order of the headings.
     *
     * @return iterable<int, list<string>>
     */
    abstract public function rows(): iterable;

    abstract protected function gym(): Organization;

    /**
     * The days the file covers, for its name.
     */
    abstract protected function describeDays(): string;

    /**
     * demo-gym-payments-2026-11-01-to-2026-11-30.csv
     */
    public function filename(): string
    {
        return "{$this->gym()->slug}-{$this->name()}-{$this->describeDays()}.csv";
    }

    /**
     * A day as the files write it: 2026-11-13, or empty.
     */
    protected function day(?DateTimeInterface $day): string
    {
        return $day === null ? '' : $day->format('Y-m-d');
    }

    /**
     * A moment where the gym is: 2026-11-13 07:30, or empty.
     */
    protected function moment(?CarbonInterface $moment): string
    {
        return $moment === null ? '' : $moment->setTimezone($this->gym()->timezone)->format('Y-m-d H:i');
    }
}
