<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ChapaCallbackController extends Controller
{
    /**
     * Chapa calls this once a member has paid on its payment page, with the
     * link's tx_ref as trx_ref. The call itself proves nothing, so we find the
     * link and ask Chapa ourselves, with the link's gym's own key, before
     * recording anything (see PaymentLink::check()). See ADR 0009.
     */
    public function __invoke(Request $request, CurrentOrganization $currentOrganization): JsonResponse
    {
        $txRef = $request->input('trx_ref') ?? $request->input('tx_ref');

        abort_unless(is_string($txRef), Response::HTTP_NOT_FOUND);

        $paymentLink = PaymentLink::query()
            ->acrossOrganizations()
            ->with('organization')
            ->where('tx_ref', $txRef)
            ->first();

        abort_if($paymentLink === null, Response::HTTP_NOT_FOUND);

        $currentOrganization->run($paymentLink->organization, function () use ($paymentLink): void {
            $chapaAccount = ChapaAccount::query()->first();

            if ($chapaAccount !== null) {
                $paymentLink->check($chapaAccount);
            }
        });

        return response()->json(['message' => 'Checked with Chapa.']);
    }
}
