<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\ChapaMode;
use App\Domains\Billing\Policies\ChapaAccountPolicy;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Database\Factories\ChapaAccountFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A gym's own Chapa account, which members' online payments go to, so the
 * platform never holds their money. Only the secret key is kept, encrypted
 * with the app key; the gym sees whether it is a test or live key and its
 * last 4 characters. See ADR 0009.
 */
#[UseFactory(ChapaAccountFactory::class)]
#[UsePolicy(ChapaAccountPolicy::class)]
class ChapaAccount extends Model
{
    /** @use HasFactory<ChapaAccountFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * Never in arrays or JSON, even by accident.
     *
     * @var list<string>
     */
    protected $hidden = ['secret_key'];

    /**
     * Connect the staff member's gym to Chapa with the secret key, replacing
     * the key it had, if any.
     */
    public static function connect(string $secretKey, Staff $connectedBy): self
    {
        $account = static::query()->firstWhere('organization_id', $connectedBy->organization_id) ?? new self;
        $account->organization()->associate($connectedBy->organization_id);
        $account->secret_key = $secretKey;
        $account->mode = ChapaMode::ofKey($secretKey);
        $account->key_hint = substr($secretKey, -4);
        $account->connectedBy()->associate($connectedBy);
        $account->save();

        return $account;
    }

    /** @return BelongsTo<Staff, $this> */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'connected_by_staff_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret_key' => 'encrypted',
            'mode' => ChapaMode::class,
        ];
    }
}
