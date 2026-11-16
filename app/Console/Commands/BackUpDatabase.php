<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BackUpDatabase extends Command
{
    /**
     * Dumps older than this are deleted after each backup.
     */
    private const DAYS_TO_KEEP = 30;

    /**
     * pg_dump may take this long before the backup is given up.
     */
    private const TIMEOUT_SECONDS = 15 * 60;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:backup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dump the database to the private backups bucket, and delete dumps older than 30 days';

    /**
     * Execute the console command. The dump is pg_dump's custom format, named
     * by the UTC date, so pg_restore can bring back the whole database or
     * single tables. A failed dump or upload throws, so it reaches the log
     * and Sentry instead of passing quietly. See ADR 0028.
     */
    public function handle(): int
    {
        if ((string) config('filesystems.disks.backups.bucket') === '') {
            throw new RuntimeException('BACKUP_AWS_BUCKET is not set: backups need a private bucket of their own.');
        }

        $today = CarbonImmutable::now('UTC');
        $name = $today->toDateString().'.dump';
        $path = (string) tempnam(sys_get_temp_dir(), 'backup');

        try {
            $this->dumpTo($path);

            $stream = fopen($path, 'r');
            Storage::disk('backups')->writeStream($name, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        } finally {
            @unlink($path);
        }

        $this->info("Backed up the database to {$name}.");

        foreach ($this->dumpsOlderThan($today->subDays(self::DAYS_TO_KEEP)) as $oldDump) {
            Storage::disk('backups')->delete($oldDump);
            $this->line("Deleted {$oldDump}.");
        }

        return self::SUCCESS;
    }

    private function dumpTo(string $path): void
    {
        $connection = DB::connection()->getConfig();

        $result = Process::env([
            'PGPASSWORD' => (string) ($connection['password'] ?? ''),
            'PGSSLMODE' => (string) ($connection['sslmode'] ?? 'prefer'),
        ])->timeout(self::TIMEOUT_SECONDS)->run([
            'pg_dump',
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--username='.$connection['username'],
            '--dbname='.$connection['database'],
            '--file='.$path,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('pg_dump failed: '.trim($result->errorOutput()));
        }
    }

    /**
     * The dumps named by a date before the given day; other files are left alone.
     *
     * @return list<string>
     */
    private function dumpsOlderThan(CarbonImmutable $day): array
    {
        $oldDumps = [];

        foreach (Storage::disk('backups')->files() as $file) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.dump$/', $file, $match) === 1 && $match[1] < $day->toDateString()) {
                $oldDumps[] = $file;
            }
        }

        return $oldDumps;
    }
}
