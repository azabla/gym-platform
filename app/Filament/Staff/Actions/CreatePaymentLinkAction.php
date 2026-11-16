<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Billing\Chapa\Exceptions\ChapaRequestFailed;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Filament\Staff\Resources\PaymentLinks\PaymentLinkResource;
use App\Rules\ValidMoneyAmount;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * A link to Chapa's payment page for what the member owes on the membership,
 * with the API's rules (StorePaymentLinkRequest, PaymentLink::refusalToOpen()).
 * The desk then copies the link from its page and sends it; a member with
 * the app sees it there too. See ADR 0009 and ADR 0029.
 */
class CreatePaymentLinkAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createPaymentLink';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Create payment link')
            ->icon(Heroicon::OutlinedLink)
            ->modalIcon(Heroicon::OutlinedLink)
            ->color('gray')
            ->visible(fn (Membership $record): bool => Gate::allows('create', PaymentLink::class) && $record->cancelled_at === null)
            ->modalDescription('A link to Chapa\'s payment page, to send the member. The money goes to the gym\'s Chapa account.')
            ->schema(fn (Membership $record): array => [
                TextInput::make('amount')
                    ->required()
                    ->default(Payment::balanceOf($record)->balanceDue->toDecimal())
                    ->rules(fn (): array => [new ValidMoneyAmount]),
                Select::make('branch_id')
                    ->label('Branch')
                    ->required()
                    ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all())
                    ->default(MemberResource::onlyBranchWorkedAt()),
            ])
            ->action(function (array $data, Membership $record, Action $action): void {
                $amount = Money::of((string) $data['amount'], $record->price->currency);
                $refusal = PaymentLink::refusalToOpen($record, $amount);

                if ($refusal !== null) {
                    Refusals::halt($action, $refusal);
                }

                try {
                    $paymentLink = PaymentLink::open(
                        membership: $record,
                        amount: $amount,
                        branch: Branch::query()->findOrFail((int) $data['branch_id']),
                        createdBy: app(CurrentStaff::class)->get(),
                        chapaAccount: ChapaAccount::query()->firstOrFail(),
                        callbackUrl: route('api.v1.chapa.callback'),
                    );
                } catch (ChapaRequestFailed $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Payment link created. Copy it and send it to the member.')->send();
                $action->redirect(PaymentLinkResource::getUrl('view', ['record' => $paymentLink]));
            });
    }
}
