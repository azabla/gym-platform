<?php

declare(strict_types=1);

use App\Domains\Shared\PhoneNumber\Exceptions\InvalidPhoneNumber;
use App\Domains\Shared\PhoneNumber\PhoneNumber;

it('reads Ethiopian numbers however they are typed', function (string $input, string $expected) {
    expect(PhoneNumber::of($input)->e164)->toBe($expected);
})->with([
    'local mobile' => ['0911234567', '+251911234567'],
    'local with spaces' => ['0911 23 45 67', '+251911234567'],
    'local with dashes' => ['0911-234-567', '+251911234567'],
    'without the leading zero' => ['911234567', '+251911234567'],
    'international' => ['+251911234567', '+251911234567'],
    'international with spaces' => ['+251 911 234 567', '+251911234567'],
    'country code without plus' => ['251911234567', '+251911234567'],
    'country code and leading zero' => ['+251 0911 234 567', '+251911234567'],
    'Safaricom mobile' => ['0711 234 567', '+251711234567'],
    'Addis Ababa landline' => ['011 551 2345', '+251115512345'],
]);

it('writes Ethiopian numbers the way they are written in Ethiopia, as Chapa expects', function (string $input, ?string $expected) {
    expect(PhoneNumber::of($input)->ethiopianNational())->toBe($expected);
})->with([
    'mobile' => ['+251 911 234 567', '0911234567'],
    'Safaricom mobile' => ['0711 234 567', '0711234567'],
    'Addis Ababa landline' => ['011 551 2345', '0115512345'],
    'another country' => ['+44 7911 123456', null],
]);

it('keeps numbers from other countries that start with a plus', function (string $input, string $expected) {
    expect(PhoneNumber::of($input)->e164)->toBe($expected);
})->with([
    'United Kingdom' => ['+44 7911 123456', '+447911123456'],
    'United States' => ['+1 (202) 555-0143', '+12025550143'],
]);

it('rejects input that is not a phone number', function (string $input) {
    PhoneNumber::of($input);
})->throws(InvalidPhoneNumber::class)->with([
    'empty' => [''],
    'letters' => ['abc'],
    'a letter inside' => ['091123456a'],
    'too short' => ['091123'],
    'too long' => ['09112345678'],
    'starts with a double zero' => ['0011234567'],
    'Ethiopian number too short' => ['+251 91123'],
    'Ethiopian number too long' => ['+2519112345678'],
    'foreign number without a plus' => ['447911123456'],
]);
