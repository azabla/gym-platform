<?php

namespace App\Console\Commands;

use App\Domains\Identity\Models\PasswordLink;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use App\Rules\ValidPhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateGym extends Command
{
    /**
     * Slugs the staff panel uses for its own pages, so no gym can take them.
     */
    private const RESERVED_SLUGS = ['login', 'logout', 'register', 'password-reset', 'email-verification', 'profile', 'tenant', 'new'];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gyms:create
        {--name= : The gym\'s name}
        {--slug= : The gym\'s address in the panel and the API, such as demo-gym}
        {--timezone=Africa/Addis_Ababa : The gym\'s time zone}
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
     * Execute the console command. Run by the platform, not by gyms: the
     * owner then adds the rest of the staff in the panel. See ADR 0019.
     */
    public function handle(CurrentOrganization $currentOrganization): int
    {
        $name = $this->option('name') ?? $this->ask('The gym\'s name');
        $details = [
            'name' => $name,
            'slug' => $this->option('slug') ?? $this->ask('Its slug, used in addresses', Str::slug((string) $name)),
            'timezone' => $this->option('timezone'),
            'branch' => $this->option('branch'),
            'owner_name' => $this->option('owner-name') ?? $this->ask('The owner\'s name'),
            'owner_phone' => $this->option('owner-phone') ?? $this->ask('The owner\'s phone'),
        ];

        $validator = Validator::make($details, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(self::RESERVED_SLUGS), Rule::unique('organizations', 'slug')],
            'timezone' => ['required', 'timezone:all'],
            'branch' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_phone' => ['required', 'string', new ValidPhoneNumber],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        /** @var array{name: string, slug: string, timezone: string, branch: string, owner_name: string, owner_phone: string} $valid */
        $valid = $validator->validated();

        $organization = new Organization(['name' => $valid['name'], 'slug' => $valid['slug']]);
        $organization->forceFill(['timezone' => $valid['timezone']]);

        $owner = DB::transaction(function () use ($organization, $valid, $currentOrganization): User {
            $organization->save();

            return $currentOrganization->run($organization, function () use ($organization, $valid): User {
                $organization->branches()->create(['name' => $valid['branch'], 'code' => 'MAIN']);

                $owner = User::forPhone(PhoneNumber::of($valid['owner_phone']), $valid['owner_name']);
                Staff::add($owner, StaffRole::Owner);

                return $owner;
            });
        });

        PasswordLink::invite($owner, $organization->name);

        $this->info("Created {$organization->name} ({$organization->slug}) with {$valid['branch']}. {$owner->name} is its owner and was sent an SMS to sign in.");

        return self::SUCCESS;
    }
}
