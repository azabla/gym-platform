<?php

declare(strict_types=1);

use App\Domains\Billing\Exceptions\ExpenseRefused;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Receipts\ExpenseReceipt;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->gym = actingAsOrganization(Organization::factory()->create());
    $this->expense = Expense::factory()->for($this->gym)->create();
});

it('keeps a photo or PDF receipt privately, replacing the one before, and links to it for a few minutes', function () {
    $receipts = app(ExpenseReceipt::class);

    $receipts->attach($this->expense, UploadedFile::fake()->image('receipt.jpg', 800, 1200)->getContent());
    $photo = $this->expense->receipt_path;

    $receipts->attach($this->expense, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

    expect($photo)->toStartWith("receipts/{$this->gym->id}/")->toEndWith('.jpg')
        ->and($this->expense->receipt_path)->toEndWith('.pdf')
        ->and($receipts->isPdf($this->expense))->toBeTrue()
        ->and($receipts->urlFor($this->expense))->not->toBeNull();

    Storage::disk('local')->assertMissing($photo);
    Storage::disk('local')->assertExists((string) $this->expense->receipt_path);
});

it('refuses a file that isn\'t a photo or PDF, whatever its name, and one too large', function () {
    $receipts = app(ExpenseReceipt::class);

    expect(fn () => $receipts->attach($this->expense, '<?php echo "not a receipt";'))->toThrow(ExpenseRefused::class, 'photo (JPEG, PNG or WebP) or a PDF')
        ->and(fn () => $receipts->attach($this->expense, str_repeat('a', ExpenseReceipt::MAX_BYTES + 1)))->toThrow(ExpenseRefused::class, 'up to 5 MB');

    expect($this->expense->refresh()->receipt_path)->toBeNull();
});
