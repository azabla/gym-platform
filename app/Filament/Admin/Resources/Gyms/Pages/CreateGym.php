<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Gyms\Pages;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Onboarding\NewGym;
use App\Domains\PlatformBilling\Models\GymSubscription;
use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Domains\PlatformBilling\SubscriptionTerms;
use App\Filament\Admin\Resources\Gyms\GymResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Opening a gym, as gyms:create does: the gym, its first branch and its
 * owner, who gets an SMS to set their password (ADR 0042).
 */
class CreateGym extends CreateRecord
{
    protected static string $resource = GymResource::class;

    protected static ?string $title = 'New gym';

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var array{name: string, slug: string, timezone: string, message_language: string, branch: string, owner_name: string, owner_phone: string, billing_plan?: int|string|null, trial_days?: int|string|null, months_per_invoice?: int|string|null} $data */
        return DB::transaction(function () use ($data): Organization {
            $owner = Organization::open(NewGym::fromValidated($data));
            $gym = Organization::query()->findOrFail($owner->organization_id);

            if (filled($data['billing_plan'] ?? null)) {
                GymSubscription::start(
                    $gym,
                    new SubscriptionTerms(PlatformPlan::query()->findOrFail((int) $data['billing_plan']), monthsPerInvoice: (int) ($data['months_per_invoice'] ?? 1)),
                    trialDays: (int) ($data['trial_days'] ?? 0),
                );
            }

            return $gym;
        });
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Gym opened. The owner has an SMS to set their password.';
    }

    protected function getRedirectUrl(): string
    {
        return GymResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
