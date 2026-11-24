<?php

declare(strict_types=1);

namespace App\Domains\Members\Photos;

use App\Domains\Members\Exceptions\MemberPhotoRefused;
use App\Domains\Members\Models\Member;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A member's photo, so the desk can see that the person at the door is the
 * member (ADR 0036). Whatever is uploaded is turned upright, cut to a square
 * around its centre and saved as a small JPEG, which leaves out everything
 * the phone wrote into the file, such as where it was taken. Photos live on
 * a private disk and are shown through links that work for a few minutes.
 */
final class MemberPhoto
{
    /** The width and height of a stored photo, in pixels. */
    public const SIZE = 512;

    /** The smallest width or height an upload may have, in pixels. */
    public const MINIMUM_SIZE = 200;

    /** How long a link to a photo works, in minutes. */
    public const LINK_MINUTES = 10;

    private const JPEG_QUALITY = 82;

    /**
     * Make the image the member's photo, replacing the one they had.
     *
     * @throws MemberPhotoRefused when it isn't an image that can be read, or is too small
     */
    public function store(Member $member, string $imageBytes): void
    {
        $previousPath = $member->photo_path;
        $path = sprintf('member-photos/%d/%s.jpg', $member->organization_id, Str::uuid()->toString());

        self::disk()->put($path, self::squareJpegOf($imageBytes));

        $member->forceFill(['photo_path' => $path])->save();

        if ($previousPath !== null) {
            self::disk()->delete($previousPath);
        }
    }

    /**
     * Take the member's photo away, if they have one.
     */
    public function remove(Member $member): void
    {
        $path = $member->photo_path;

        if ($path === null) {
            return;
        }

        $member->forceFill(['photo_path' => null])->save();
        self::disk()->delete($path);
    }

    /**
     * A link to the member's photo that works for a few minutes, or null when
     * they have none.
     */
    public function urlFor(Member $member): ?string
    {
        $path = $member->photo_path;

        return $path === null ? null : self::disk()->temporaryUrl($path, now()->addMinutes(self::LINK_MINUTES));
    }

    /**
     * The image, upright, cut to a centred square and resized, as a JPEG.
     *
     * @throws MemberPhotoRefused
     */
    private static function squareJpegOf(string $imageBytes): string
    {
        $image = $imageBytes === '' ? false : @imagecreatefromstring($imageBytes);

        if (! $image instanceof GdImage) {
            throw MemberPhotoRefused::notAnImage();
        }

        $image = self::upright($image, $imageBytes);
        $width = imagesx($image);
        $height = imagesy($image);

        if (min($width, $height) < self::MINIMUM_SIZE) {
            throw MemberPhotoRefused::tooSmall(self::MINIMUM_SIZE);
        }

        $side = min($width, $height);
        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($square, 0, 0, (int) imagecolorallocate($square, 255, 255, 255));
        imagecopyresampled($square, $image, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);

        ob_start();
        imagejpeg($square, null, self::JPEG_QUALITY);

        return (string) ob_get_clean();
    }

    /**
     * The image turned the way the camera says it was held, for JPEGs that
     * say so.
     */
    private static function upright(GdImage $image, string $imageBytes): GdImage
    {
        if (! str_starts_with($imageBytes, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($imageBytes));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $degrees = match ($orientation) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };

        if ($degrees === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $degrees, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    private static function disk(): Filesystem
    {
        return Storage::disk((string) config('filesystems.photos_disk'));
    }
}
