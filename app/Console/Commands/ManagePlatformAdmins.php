<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Identity\Enums\PasswordLinkPurpose;
use App\Domains\Identity\Models\PasswordLink;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\PhoneNumber\Exceptions\InvalidPhoneNumber;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Illuminate\Console\Command;

class ManagePlatformAdmins extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'platform:admins
        {action : add, remove or list}
        {--phone= : The person\'s phone, which they sign in with}
        {--name= : Their name, for someone who has no account yet}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add, remove or list the platform admins, who open the admin panel at /admin';

    /**
     * Execute the console command. The only way anyone becomes a platform
     * admin: never from a gym or a panel (ADR 0042).
     */
    public function handle(): int
    {
        return match ($this->argument('action')) {
            'add' => $this->add(),
            'remove' => $this->remove(),
            'list' => $this->listAdmins(),
            default => $this->refuse('Choose add, remove or list.'),
        };
    }

    private function add(): int
    {
        $phone = $this->phone();

        if ($phone === null) {
            return self::FAILURE;
        }

        $user = User::query()->where('phone', $phone->e164)->first();

        if ($user === null) {
            $name = $this->option('name') ?? $this->ask('Their name');

            if (! is_string($name) || trim($name) === '') {
                return $this->refuse('Give their name with --name.');
            }

            $user = User::forPhone($phone, trim($name));
        }

        $user->makePlatformAdmin();

        if ($user->password === null) {
            $token = PasswordLink::issue($user, PasswordLinkPurpose::Invite);
            $this->info("{$user->name} is now a platform admin. Send them this link to set their password, then they sign in at /admin:");
            $this->line(route('staff.password.edit', $token));

            return self::SUCCESS;
        }

        $this->info("{$user->name} is now a platform admin and signs in at /admin with the password they have.");

        return self::SUCCESS;
    }

    private function remove(): int
    {
        $phone = $this->phone();

        if ($phone === null) {
            return self::FAILURE;
        }

        $user = User::query()->where('phone', $phone->e164)->where('is_platform_admin', true)->first();

        if ($user === null) {
            return $this->refuse('No platform admin has this phone.');
        }

        $user->makePlatformAdmin(false);
        $this->info("{$user->name} is no longer a platform admin.");

        return self::SUCCESS;
    }

    private function listAdmins(): int
    {
        $admins = User::query()->where('is_platform_admin', true)->orderBy('name')->get(['name', 'phone', 'email']);

        if ($admins->isEmpty()) {
            $this->info('There are no platform admins yet. Add one with: php artisan platform:admins add --phone=… --name=…');

            return self::SUCCESS;
        }

        $this->table(['Name', 'Phone', 'Email'], $admins->map(fn (User $admin): array => [$admin->name, $admin->phone, $admin->email])->all());

        return self::SUCCESS;
    }

    private function phone(): ?PhoneNumber
    {
        $typed = $this->option('phone') ?? $this->ask('Their phone');

        try {
            return PhoneNumber::of(is_string($typed) ? $typed : '');
        } catch (InvalidPhoneNumber) {
            $this->refuse('Give a phone number like 0911 234 567.');

            return null;
        }
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
