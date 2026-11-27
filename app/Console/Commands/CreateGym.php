<?php

namespace App\Console\Commands;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Onboarding\NewGym;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateGym extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gyms:create
        {--name= : The gym\'s name}
        {--slug= : The gym\'s address in the panel and the API, such as demo-gym}
        {--timezone=Africa/Addis_Ababa : The gym\'s time zone}
        {--language=en : The language its members get messages in: en or am}
        {--branch=Main Branch : The name of its first branch}
        {--owner-name= : The owner\'s name}
        {--owner-phone= : The owner\'s phone, which they sign in with}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a gym with its first branch and its owner, who gets an SMS to set their password';

    /**
     * Execute the console command. Run by the platform, not by gyms, as the
     * admin panel's Create gym is: the owner then adds the rest of the staff
     * in the panel. See ADR 0019 and ADR 0042.
     */
    public function handle(): int
    {
        $name = $this->option('name') ?? $this->ask('The gym\'s name');

        $details = [
            'name' => $name,
            'slug' => $this->option('slug') ?? $this->ask('Its slug, used in addresses', Str::slug((string) $name)),
            'timezone' => $this->option('timezone'),
            'message_language' => $this->option('language'),
            'branch' => $this->option('branch'),
            'owner_name' => $this->option('owner-name') ?? $this->ask('The owner\'s name'),
            'owner_phone' => $this->option('owner-phone') ?? $this->ask('The owner\'s phone'),
        ];

        $validator = Validator::make($details, NewGym::rules());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        /** @var array{name: string, slug: string, timezone: string, message_language: string, branch: string, owner_name: string, owner_phone: string} $valid */
        $valid = $validator->validated();
        $owner = Organization::open(NewGym::fromValidated($valid));

        $this->info("Created {$valid['name']} ({$valid['slug']}) with {$valid['branch']}. {$owner->user->name} is its owner and was sent an SMS to sign in.");

        return self::SUCCESS;
    }
}
