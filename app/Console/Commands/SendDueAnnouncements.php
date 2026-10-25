<?php

namespace App\Console\Commands;

use App\Domains\Announcements\Models\Announcement;
use App\Jobs\SendAnnouncement;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SendDueAnnouncements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'announcements:send-due';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send the scheduled announcements whose time has come';

    /**
     * Execute the console command. It runs every minute and queues a job for
     * each due announcement of every gym; the job sends each one once,
     * however often it is queued. See ADR 0015.
     */
    public function handle(): int
    {
        $due = 0;

        Announcement::query()
            ->acrossOrganizations()
            ->due()
            ->orderBy('send_at')
            ->each(function (Announcement $announcement) use (&$due): void {
                SendAnnouncement::dispatch($announcement);
                $due++;
            });

        $this->info("Queued {$due} due ".Str::plural('announcement', $due).'.');

        return self::SUCCESS;
    }
}
