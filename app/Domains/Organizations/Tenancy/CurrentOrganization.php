<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Tenancy;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\Exceptions\NoCurrentOrganization;
use Closure;
use Illuminate\Container\Attributes\Scoped;

/**
 * The organization (gym) the current request or queued job is acting for.
 *
 * Scoped: the container forgets it at the start of every request and queue
 * job, so one gym's context can never leak into the next unit of work.
 *
 * See docs/adr/0003-multi-tenancy-with-organization-scoping.md.
 */
#[Scoped]
final class CurrentOrganization
{
    private ?Organization $organization = null;

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function clear(): void
    {
        $this->organization = null;
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }

    public function get(): Organization
    {
        return $this->organization ?? throw NoCurrentOrganization::isSet();
    }

    public function id(): int
    {
        return $this->get()->getKey();
    }

    /**
     * Run the callback as the given organization, then restore whichever
     * organization (or none) was current before, even if the callback throws.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(Organization $organization, Closure $callback): mixed
    {
        $previous = $this->organization;
        $this->organization = $organization;

        try {
            return $callback();
        } finally {
            $this->organization = $previous;
        }
    }
}
