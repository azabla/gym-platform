<?php

namespace App\Console\Commands;

use App\Domains\Attendance\DoorList\DoorListSigner;
use Illuminate\Console\Command;

class GenerateDoorListKey extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'door-list:generate-key';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make a new Ed25519 key pair for signing the staff app\'s offline door lists';

    /**
     * Print a new key pair: the secret key goes in DOOR_LIST_SIGNING_KEY, and
     * the public key in the staff app. Nothing is saved. Changing the key
     * means the staff app needs the new public key. See ADR 0038.
     */
    public function handle(): int
    {
        $pair = DoorListSigner::generateKeyPair();

        $this->line('Set this on the server (keep it secret):');
        $this->line("DOOR_LIST_SIGNING_KEY={$pair['secret_key']}");
        $this->newLine();
        $this->line('Give this to the staff app, which checks door lists with it:');
        $this->line($pair['public_key']);

        return self::SUCCESS;
    }
}
