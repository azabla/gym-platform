<?php

use App\Domains\Announcements\Exceptions\AnnouncementRefused;
use App\Domains\Attendance\DoorList\Exceptions\DoorListNotSetUp;
use App\Domains\Billing\Chapa\Exceptions\ChapaRequestFailed;
use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Members\Exceptions\MemberPhotoRefused;
use App\Domains\Memberships\Exceptions\MembershipRefused;
use App\Domains\Memberships\Exceptions\PromoCodeRefused;
use App\Domains\Organizations\Exceptions\BranchClosureRefused;
use App\Domains\Organizations\Exceptions\GymSuspended;
use App\Domains\Organizations\Exceptions\StaffRefused;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Http\Middleware\ActAsMember;
use App\Http\Middleware\ActAsOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function Sentry\configureScope;

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

        // A promo code that can't be used for this member and plan today (ADR 0030).
        $exceptions->map(fn (PromoCodeRefused $exception) => ValidationException::withMessages([
            $exception->field => $exception->getMessage(),
        ]));

        // An announcement the gym's rules don't allow (ADR 0023).
        $exceptions->map(fn (AnnouncementRefused $exception) => ValidationException::withMessages([
            $exception->field => $exception->getMessage(),
        ]));

        // A staff change that would leave the gym without an owner (ADR 0019).
        $exceptions->map(fn (StaffRefused $exception) => ValidationException::withMessages([
            $exception->field => $exception->getMessage(),
        ]));

        // A closure in the past, too long, or over another (ADR 0037).
        $exceptions->map(fn (BranchClosureRefused $exception) => ValidationException::withMessages([
            $exception->field => $exception->getMessage(),
        ]));

        // A photo that isn't an image that can be read, or is too small (ADR 0036).
        $exceptions->map(fn (MemberPhotoRefused $exception) => ValidationException::withMessages([
            $exception->field => $exception->getMessage(),
        ]));

        // A gym the platform paused: forbidden, with a code the apps can show a screen for (ADR 0043).
        $exceptions->dontReport(GymSuspended::class);
        $exceptions->render(function (GymSuspended $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $exception->getMessage(), 'code' => GymSuspended::CODE], 403);
            }
        });

        // Offline scanning without a signing key: not available, with how to set it up (ADR 0038).
        $exceptions->render(function (DoorListNotSetUp $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $exception->getMessage()], 503);
            }
        });

        // Chapa refused a request or didn't answer: a bad gateway, with Chapa's reason.
        $exceptions->render(function (ChapaRequestFailed $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $exception->getMessage()], 502);
            }
        });

        // Unhandled errors go to Sentry when SENTRY_LARAVEL_DSN is set (ADR 0028), with
        // only the user's id and the gym's slug: no names, phone numbers or request bodies.
        // Validation errors, 404s and the refusals above are expected answers, so Laravel
        // doesn't report them.
        $exceptions->reportable(function (Throwable $exception): void {
            $currentOrganization = app(CurrentOrganization::class);
            $userId = auth()->id();

            configureScope(function (Scope $scope) use ($currentOrganization, $userId): void {
                $scope->setUser($userId === null ? [] : ['id' => (string) $userId]);
                $scope->setTag('gym', $currentOrganization->has() ? $currentOrganization->get()->slug : 'none');
            });
        });
        Integration::handles($exceptions);
    })->create();
