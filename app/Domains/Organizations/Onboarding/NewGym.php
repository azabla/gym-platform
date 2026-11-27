<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Onboarding;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Language\Language;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use App\Rules\ValidPhoneNumber;
use Illuminate\Validation\Rule;

/**
 * What the platform needs to open a gym: its name and address in the
 * panel and the API, where it is, the language its members get messages
 * in, its first branch and its owner. The gyms:create command and the
 * admin panel both check it with rules() and open it with
 * Organization::open(). See ADR 0019 and ADR 0042.
 */
final readonly class NewGym
{
    public function __construct(
        public string $name,
        public string $slug,
        public string $timezone,
        public Language $messageLanguage,
        public string $branchName,
        public string $ownerName,
        public PhoneNumber $ownerPhone,
    ) {}

    /**
     * The checks on what was typed, keyed as fromValidated() reads them.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(Organization::RESERVED_SLUGS), Rule::unique('organizations', 'slug')],
            'timezone' => ['required', 'timezone:all'],
            'message_language' => ['required', Rule::in(Language::codes())],
            'branch' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_phone' => ['required', 'string', new ValidPhoneNumber],
        ];
    }

    /**
     * The gym from input that passed rules().
     *
     * @param  array{name: string, slug: string, timezone: string, message_language: string, branch: string, owner_name: string, owner_phone: string}  $valid
     */
    public static function fromValidated(array $valid): self
    {
        return new self(
            name: $valid['name'],
            slug: $valid['slug'],
            timezone: $valid['timezone'],
            messageLanguage: Language::from($valid['message_language']),
            branchName: $valid['branch'],
            ownerName: $valid['owner_name'],
            ownerPhone: PhoneNumber::of($valid['owner_phone']),
        );
    }
}
