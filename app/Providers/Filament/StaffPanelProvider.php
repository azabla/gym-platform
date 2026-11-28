<?php

namespace App\Providers\Filament;

use App\Domains\Organizations\Models\Organization;
use App\Filament\Staff\Auth\Login;
use App\Filament\Staff\Middleware\ActAsTenant;
use App\Filament\Staff\Middleware\UseStaffLanguage;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Pages\Dashboard;
use App\Filament\Staff\Pages\MyDevices;
use App\Filament\Staff\Pages\MyNotifications;
use App\Filament\Staff\Support\PlatformBillBanner;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The staff panel at /staff: staff sign in with their email and password and
 * work in one gym at a time, at /staff/{gym slug}. See ADR 0017.
 */
class StaffPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('staff')
            ->path('staff')
            ->login(Login::class)
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): HtmlString => new HtmlString(sprintf(
                    '<p class="fi-ta-text text-center text-sm mt-4"><a href="%s" class="fi-link fi-color-primary font-semibold underline">Forgot your password?</a></p>',
                    e(route('staff.password.forgot')),
                )),
            )
            // Owners see the platform's unpaid invoice on every page (ADR 0045).
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): ?HtmlString => PlatformBillBanner::html())
            ->authGuard('staff_panel')
            ->tenant(Organization::class, slugAttribute: 'slug', ownershipRelationship: 'organization')
            // The language first, so a paused gym's page is in the staff member's language (ADR 0043).
            ->tenantMiddleware([
                UseStaffLanguage::class,
                ActAsTenant::class,
            ], isPersistent: true)
            // The look of the panel (ADR 0033): the gym's name as the brand, an energetic
            // orange on slate, Inter, light or dark as the device is, and grouped navigation.
            ->brandName(fn (): string => Filament::getTenant() instanceof Organization ? Filament::getTenant()->name : 'Gym Platform')
            ->favicon(asset('favicon.ico'))
            ->colors([
                'primary' => Color::Orange,
                'gray' => Color::Slate,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'info' => Color::Sky,
            ])
            ->font('Inter')
            ->darkMode()
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups(NavigationGroup::class)
            // Each staff member's own message language, push and SMS (ADR 0034 and ADR 0035).
            ->userMenuItems([
                Action::make('myNotifications')
                    ->label('My notifications')
                    ->icon(Heroicon::OutlinedBellAlert)
                    ->url(fn (): string => MyNotifications::getUrl()),
                // The phones signed in as them, to sign out a lost one (ADR 0046).
                Action::make('myDevices')
                    ->label('My devices')
                    ->icon(Heroicon::OutlinedDevicePhoneMobile)
                    ->url(fn (): string => MyDevices::getUrl()),
            ])
            ->discoverResources(in: app_path('Filament/Staff/Resources'), for: 'App\Filament\Staff\Resources')
            ->discoverPages(in: app_path('Filament/Staff/Pages'), for: 'App\Filament\Staff\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Staff/Widgets'), for: 'App\Filament\Staff\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
