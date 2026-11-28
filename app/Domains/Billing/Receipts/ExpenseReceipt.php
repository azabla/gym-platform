<?php

declare(strict_types=1);

namespace App\Domains\Billing\Receipts;

use App\Domains\Billing\Exceptions\ExpenseRefused;
use App\Domains\Billing\Models\Expense;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The receipt of an expense (ADR 0047): a photo (JPEG, PNG or WebP) or a PDF
 * of up to 5 MB, kept as it was given on the private photos disk (ADR 0036)
 * and shown through links that work for a few minutes. What a file is comes
 * from its bytes, not its name.
 */
final class ExpenseReceipt
{
    /** The largest receipt, in bytes. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** How long a link to a receipt works, in minutes. */
    public const LINK_MINUTES = 10;

    /** @var array<string, string> what a receipt may be, and its file extension */
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /**
     * Attach the file as the expense's receipt, replacing the one it had.
     *
     * @throws ExpenseRefused when it is too large or not a photo or PDF
     */
    public function attach(Expense $expense, string $bytes): void
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw ExpenseRefused::receiptTooLarge();
        }

        $type = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension = self::TYPES[$type] ?? throw ExpenseRefused::receiptNotAccepted();

        $previousPath = $expense->receipt_path;
        $path = sprintf('receipts/%d/%s.%s', $expense->organization_id, Str::uuid()->toString(), $extension);

        self::disk()->put($path, $bytes);
        $expense->forceFill(['receipt_path' => $path])->save();

        if ($previousPath !== null) {
            self::disk()->delete($previousPath);
        }
    }

    /**
     * A link to the receipt that works for a few minutes, or null when there is none.
     */
    public function urlFor(Expense $expense): ?string
    {
        $path = $expense->receipt_path;

        return $path === null ? null : self::disk()->temporaryUrl($path, now()->addMinutes(self::LINK_MINUTES));
    }

    public function isPdf(Expense $expense): bool
    {
        return str_ends_with((string) $expense->receipt_path, '.pdf');
    }

    private static function disk(): Filesystem
    {
        return Storage::disk((string) config('filesystems.photos_disk'));
    }
}
