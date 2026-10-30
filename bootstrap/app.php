<?php

use App\Domains\Billing\Chapa\Exceptions\ChapaRequestFailed;
use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Memberships\Exceptions\MembershipRefused;
use App\Domains\Organizations\Exceptions\StaffRefused;
use App\Http\Middleware\ActAsMember;
use App\Http\Middleware\ActAsOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'organization' => ActAsOrganization::class,
            'member' => ActAsMember::class,
        ]);

        // Pick the organization before route-model binding, so bound models are scoped to it.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ActAsOrganization::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ActAsMember::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One answer for every missing API record, without model class names in it.
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Not found.'], 404);
            }
        });

        // A payment that another desk's payment made too large gets the same
        // answer as one the request refused.
        $exceptions->map(fn (PaymentRefused $exception) => ValidationException::withMessages([
            $exception->field => $exception->getMessage(),
        ]));

        // A sale, freeze or cancellation the membership rules refuse.
        $exceptions->map(fn (MembershipRefused $exception) => ValidationException::withMessages([
            $exception->field => $exception->getMessage(),
        ]));

        // A staff change that would leave the gym without an owner (ADR 0019).
        $exceptions->map(fn (StaffRefused $exception) => ValidationException::withMessages([
            $exception->field => $exception->getMessage(),
        ]));

        // Chapa refused a request or didn't answer: a bad gateway, with Chapa's reason.
        $exceptions->render(function (ChapaRequestFailed $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $exception->getMessage()], 502);
            }
        });
    })->create();
