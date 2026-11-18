<?php

declare(strict_types=1);

/**
 * Every message, with the dotted key it has in its file.
 *
 * @param  array<string, mixed>  $messages
 * @return array<string, string>
 */
function flattenMessages(array $messages, string $prefix = ''): array
{
    $flat = [];

    foreach ($messages as $key => $message) {
        if (is_array($message)) {
            $flat = [...$flat, ...flattenMessages($message, "{$prefix}{$key}.")];
        } else {
            $flat["{$prefix}{$key}"] = (string) $message;
        }
    }

    return $flat;
}

/**
 * The placeholders a message fills in, such as [":gym", ":plan"].
 *
 * @return list<string>
 */
function placeholdersOf(string $message): array
{
    preg_match_all('/:[a-z_]+/', $message, $matches);
    $placeholders = array_values(array_unique($matches[0]));
    sort($placeholders);

    return $placeholders;
}

it('has every English message in Amharic, with the same placeholders', function (string $file) {
    $english = flattenMessages(require lang_path("en/{$file}"));
    $amharic = flattenMessages(require lang_path("am/{$file}"));

    expect(array_keys($amharic))->toEqualCanonicalizing(array_keys($english));

    foreach ($english as $key => $message) {
        expect(placeholdersOf($amharic[$key]))->toBe(placeholdersOf($message), "{$file}: {$key}");
    }
})->with(['notifications.php', 'staff_notifications.php', 'sms.php']);
