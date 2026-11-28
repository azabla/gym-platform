<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling\Models;

use App\Casts\AsMoney;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use App\Domains\PlatformBilling\Enums\GymPaymentMethod;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money a gym paid towards one of the platform's invoices, recorded by a
 * platform admin from the gym's receipt (ADR 0045). Written only by
 * GymInvoice::recordPayment().
 *
 * @property int $gym_invoice_id
 * @property Money $amount
 * @property GymPaymentMethod $method
 * @property string|null $reference
 * @property CarbonImmutable $paid_on
 * @property-read User $recordedBy
 */
class GymInvoicePayment extends Model
{
    use BelongsToOrganization;

    /** @return BelongsTo<GymInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(GymInvoice::class, 'gym_invoice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gym_invoice_id' => 'integer',
            'amount' => AsMoney::class.':amount_minor,currency',
            'method' => GymPaymentMethod::class,
            'paid_on' => 'immutable_date',
        ];
    }
}
