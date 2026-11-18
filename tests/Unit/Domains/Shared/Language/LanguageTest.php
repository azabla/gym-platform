<?php

declare(strict_types=1);

use App\Domains\Shared\Language\Language;

it('reads a language from a code, with or without a region', function (?string $code, Language $language) {
    expect(Language::fromCode($code))->toBe($language);
})->with([
    'Amharic' => ['am', Language::Amharic],
    'Amharic in Ethiopia' => ['am-ET', Language::Amharic],
    'upper case' => ['AM', Language::Amharic],
    'English' => ['en-US', Language::English],
    'a language we do not write in' => ['fr', Language::English],
    'none' => [null, Language::English],
]);
