<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Hours;

/**
 * When a branch is open on one day, from opening until closing, as
 * "HH:MM:SS" in the gym's time zone. Closing earlier than opening means
 * after midnight, on the next day: 18:00–02:00 runs until 02:00 the next
 * morning. See ADR 0016 and ADR 0037.
 */
final readonly class OpeningWindow
{
    public function __construct(
        public string $opensAt,
        public string $closesAt,
    ) {}

    /**
     * Open the whole day, as a branch without opening hours is.
     */
    public static function allDay(): self
    {
        return new self('00:00:00', '24:00:00');
    }

    /**
     * Whether the time of day falls within the window on its own day: from
     * opening until just before closing, or until midnight when it runs past
     * midnight.
     */
    public function covers(string $timeOfDay): bool
    {
        return $timeOfDay >= $this->opensAt && ($this->runsPastMidnight() || $timeOfDay < $this->closesAt);
    }

    /**
     * Whether the time of day on the next day still falls within the
     * window: before closing, when it runs past midnight.
     */
    public function coversTheNextMorning(string $timeOfDay): bool
    {
        return $this->runsPastMidnight() && $timeOfDay < $this->closesAt;
    }

    public function runsPastMidnight(): bool
    {
        return $this->closesAt < $this->opensAt;
    }
}
