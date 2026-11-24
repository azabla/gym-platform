<?php

declare(strict_types=1);

use App\Domains\Members\Exceptions\MemberPhotoRefused;
use App\Domains\Members\Models\Member;
use App\Domains\Members\Photos\MemberPhoto;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.photos_disk' => 'local']);
});

/**
 * An image of the given size, its left half red and its right half blue.
 */
function photoBytes(int $width, int $height, string $type = 'jpeg'): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, (int) imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 255));

    ob_start();
    $type === 'png' ? imagepng($image) : imagejpeg($image, null, 95);

    return (string) ob_get_clean();
}

/**
 * The JPEG with an EXIF block saying the camera was turned (orientation 6:
 * show it turned a quarter clockwise), as phones write it.
 */
function withExifOrientation6(string $jpeg): string
{
    $tiff = "MM\x00\x2A\x00\x00\x00\x08"
        ."\x00\x01"
        ."\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00"
        ."\x00\x00\x00\x00";
    $payload = "Exif\x00\x00".$tiff;

    return "\xFF\xD8\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
}

/**
 * The colour of a pixel of a stored photo, as [red, green, blue].
 *
 * @return array{int, int, int}
 */
function colourAt(string $jpeg, int $x, int $y): array
{
    $image = imagecreatefromstring($jpeg);
    $rgb = imagecolorat($image, $x, $y);

    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
}

it('keeps a photo as a 512 pixel square JPEG, cut from the centre', function () {
    $member = Member::factory()->create();

    app(MemberPhoto::class)->store($member, photoBytes(1200, 800, 'png'));

    $stored = Storage::disk('local')->get((string) $member->refresh()->photo_path);
    [$width, $height, $type] = getimagesizefromstring($stored);

    expect($member->photo_path)->toStartWith("member-photos/{$member->organization_id}/")->toEndWith('.jpg')
        ->and([$width, $height, $type])->toBe([512, 512, IMAGETYPE_JPEG])
        ->and(colourAt($stored, 10, 256)[0])->toBeGreaterThan(200)
        ->and(colourAt($stored, 500, 256)[2])->toBeGreaterThan(200);
});

it('turns a photo upright and leaves out what the camera wrote into it', function () {
    $member = Member::factory()->create();
    $upload = withExifOrientation6(photoBytes(400, 400));

    expect(exif_read_data('data://image/jpeg;base64,'.base64_encode($upload))['Orientation'])->toBe(6);

    app(MemberPhoto::class)->store($member, $upload);
    $stored = Storage::disk('local')->get((string) $member->refresh()->photo_path);

    expect(colourAt($stored, 256, 10)[0])->toBeGreaterThan(200)
        ->and(colourAt($stored, 256, 500)[2])->toBeGreaterThan(200)
        ->and(@exif_read_data('data://image/jpeg;base64,'.base64_encode($stored)) ?: [])->not->toHaveKey('Orientation');
});

it('replaces the old photo, and removes it', function () {
    $member = Member::factory()->create();
    $photos = app(MemberPhoto::class);

    $photos->store($member, photoBytes(300, 300));
    $first = $member->photo_path;
    $photos->store($member, photoBytes(300, 300));

    expect($member->photo_path)->not->toBe($first)
        ->and(Storage::disk('local')->exists((string) $first))->toBeFalse()
        ->and(Storage::disk('local')->exists((string) $member->photo_path))->toBeTrue()
        ->and($photos->urlFor($member))->toBeString();

    $second = $member->photo_path;
    $photos->remove($member);

    expect($member->refresh()->photo_path)->toBeNull()
        ->and(Storage::disk('local')->exists((string) $second))->toBeFalse()
        ->and($photos->urlFor($member))->toBeNull();
});

it('refuses what isn\'t an image, and an image too small to show the person', function (string $upload, string $message) {
    $member = Member::factory()->create();

    expect(fn () => app(MemberPhoto::class)->store($member, $upload))->toThrow(MemberPhotoRefused::class, $message)
        ->and($member->refresh()->photo_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'not an image' => ['%PDF-1.4 not a photo', 'must be a JPEG, PNG or WebP image'],
    'too small' => fn () => [photoBytes(150, 400), 'at least 200 pixels'],
]);
