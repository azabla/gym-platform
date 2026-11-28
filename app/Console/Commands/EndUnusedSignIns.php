<?php

namespace App\Console\Commands;

use App\Domains\Identity\SignIns;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class EndUnusedSignIns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sign-ins:end-unused';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'End phones\' sign-ins unused for 30 days (staff app) or 90 days (members app)';

    /**
     * Run daily. An unused sign-in already stops working when it is too old;
     * this deletes it, with the QR credentials issued under it. See ADR 0046.
     */
    public function handle(): int
    {
        $ended = SignIns::endUnused();

        $this->info("Ended {$ended} unused ".Str::plural('sign-in', $ended).'.');

        return self::SUCCESS;
    }
}
