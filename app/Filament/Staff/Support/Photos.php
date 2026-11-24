<?php

declare(strict_types=1);

namespace App\Filament\Staff\Support;

use App\Domains\Members\Models\Member;
use App\Domains\Members\Photos\MemberPhoto;

/**
 * Member photos as the panel shows them (ADR 0036): a link that works for a
 * few minutes, or the member's initials on a circle when they have no photo,
 * drawn here so no outside service learns their name.
 */
final class Photos
{
    public static function urlFor(?Member $member): string
    {
        if ($member === null) {
            return self::initials('?');
        }

        return app(MemberPhoto::class)->urlFor($member) ?? self::initials((string) $member->full_name);
    }

    /**
     * An SVG of the name's initials, such as "HG" for Hana Girma, as a data URI.
     */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
        $initials = mb_strtoupper(mb_substr($words[0], 0, 1).(count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : ''));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#fed7aa"/>'
            .'<text x="32" y="41" font-family="Inter, sans-serif" font-size="24" font-weight="600" text-anchor="middle" fill="#9a3412">'
            .htmlspecialchars($initials, ENT_XML1)
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
