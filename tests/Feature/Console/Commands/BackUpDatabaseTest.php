<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.disks.backups.bucket' => 'gym-platform-backups']);
    Storage::fake('backups');
    $this->travelTo(CarbonImmutable::parse('2026-11-15 23:00', 'UTC'));
});

/**
 * Fake pg_dump writing a dump to the file it is given, and answering as given.
 */
function fakePgDump(int $exitCode = 0, string $errorOutput = ''): void
{
    Process::fake(function (PendingProcess $process) use ($exitCode, $errorOutput) {
        $file = collect((array) $process->command)->first(fn (string $argument) => str_starts_with($argument, '--file='));
        file_put_contents(substr((string) $file, strlen('--file=')), 'PGDMP dump');

        return Process::result(exitCode: $exitCode, errorOutput: $errorOutput);
    });
}

it('dumps the database in pg_dump\'s custom format to the backups bucket, named by the date', function () {
    fakePgDump();

    $this->artisan('db:backup')->assertSuccessful();

    Storage::disk('backups')->assertExists('2026-11-15.dump');
    expect(Storage::disk('backups')->get('2026-11-15.dump'))->toBe('PGDMP dump');
    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'pg_dump'
        && in_array('--format=custom', (array) $process->command, true)
        && array_key_exists('PGPASSWORD', $process->environment));
});

it('deletes dumps older than 30 days, and leaves other files alone', function () {
    fakePgDump();
    foreach (['2026-10-15.dump', '2026-10-16.dump', 'notes.txt'] as $file) {
        Storage::disk('backups')->put($file, 'old');
    }

    $this->artisan('db:backup')->assertSuccessful();

    Storage::disk('backups')->assertMissing('2026-10-15.dump');
    Storage::disk('backups')->assertExists(['2026-10-16.dump', 'notes.txt', '2026-11-15.dump']);
});

it('fails loudly, keeping the old dumps, when pg_dump fails', function () {
    fakePgDump(1, 'connection to server failed');
    Storage::disk('backups')->put('2026-10-01.dump', 'old');

    expect(fn () => $this->artisan('db:backup'))->toThrow(RuntimeException::class, 'pg_dump failed: connection to server failed');

    Storage::disk('backups')->assertMissing('2026-11-15.dump');
    Storage::disk('backups')->assertExists('2026-10-01.dump');
});

it('refuses to back up without a private bucket of its own', function () {
    config(['filesystems.disks.backups.bucket' => null]);
    Process::fake();

    expect(fn () => $this->artisan('db:backup'))->toThrow(RuntimeException::class, 'BACKUP_AWS_BUCKET is not set');

    Process::assertNothingRan();
});
