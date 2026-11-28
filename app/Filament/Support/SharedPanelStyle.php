<?php

namespace App\Filament\Support;

use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;

final class SharedPanelStyle
{
    public static function configure(Panel $panel): Panel
    {
        return $panel
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => Color::Zinc,
                'gray' => Color::Zinc,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'info' => Color::Sky,
            ])
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn () => view('filament.shared.copy-page-link'),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('components.ui.page-link-runtime'),
            )
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                fn (array $scopes) => view('filament.shared.resource-back', ['scopes' => $scopes]),
            );
    }
}
