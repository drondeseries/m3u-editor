<?php

namespace App\Providers\Filament;

use Exception;
use App\Filament\Auth\Login;
use App\Filament\Auth\EditProfile;
use App\Filament\Pages\CustomDashboard;
use App\Filament\Widgets\DiscordWidget;
use App\Filament\Widgets\KoFiWidget;
use App\Filament\Widgets\PayPalDonateWidget;
use App\Filament\Widgets\StatsOverview;
use App\Settings\GeneralSettings;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Support\Enums\MaxWidth;
use Hydrat\TableLayoutToggle\TableLayoutTogglePlugin;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;

class AdminPanelProvider extends PanelProvider
{
    protected static ?string $navigationIcon = 'heroicon-o-tachometer';
    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-tachometer';
    }

    public function panel(Panel $panel): Panel
    {
        $userPreferences = app(GeneralSettings::class);
        $settings = [
            'navigation_position' => 'left',
            'show_breadcrumbs' => true,
            'show_jobs_navigation' => false,
            'content_width' => MaxWidth::ScreenLarge,
        ];
        try {
            $settings = [
                'navigation_position' => $userPreferences->navigation_position ?? $settings['navigation_position'],
                'show_breadcrumbs' => $userPreferences->show_breadcrumbs ?? $settings['show_breadcrumbs'],
                'show_jobs_navigation' => $userPreferences->show_jobs_navigation ?? $settings['show_jobs_navigation'],
                'content_width' => $userPreferences->content_width ?? $settings['content_width'],
            ];
        } catch (Exception $e) {
            // Ignore
        }
        $adminPanel = $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login(Login::class)
            ->profile(EditProfile::class, isSimple: false)
            ->brandName('m3u editor')
            ->brandLogo(fn() => view('filament.admin.logo'))
            ->favicon('/favicon.png')
            ->brandLogoHeight('2.5rem')
            ->databaseNotifications()
            ->databaseNotificationsPolling('10s')
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                CustomDashboard::class
            ])
            ->breadcrumbs($settings['show_breadcrumbs'])
            // Don't auto discover widgets, we'll manually register them
            // ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                AccountWidget::class,
                DiscordWidget::class,
                // PayPalDonateWidget::class,
                KoFiWidget::class,
                StatsOverview::class,
            ])
            ->plugins([
                TableLayoutTogglePlugin::make(),
            ])
            ->maxContentWidth($settings['content_width'])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->spa()
            ->spaUrlExceptions(fn(): array => [
                '*/playlist.m3u',
                '*/epg.xml',
                'epgs/*/epg.xml'
            ])
            ->renderHook(
                // PanelsRenderHook::BODY_END,
                PanelsRenderHook::FOOTER,
                fn() => view('footer')
            );

        if ($settings['navigation_position'] === 'top') {
            $adminPanel->topNavigation();
        } else {
            $adminPanel->sidebarCollapsibleOnDesktop();
        }
        return $adminPanel;
    }

    public function register(): void
    {
        parent::register();
        FilamentView::registerRenderHook('panels::body.end', fn() => Blade::render("@vite('resources/js/app.js')"));
    }
}
