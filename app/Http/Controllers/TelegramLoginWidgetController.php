<?php

namespace App\Http\Controllers;

use App\Services\TelegramAuthService;
use App\Services\TelegramLoginWidgetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TelegramLoginWidgetController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramLoginWidgetService $widgetService,
        TelegramAuthService $authService
    ): RedirectResponse {
        $telegramUser = $widgetService->validate($request->all());

        $user = $authService->loginOrCreate($telegramUser);

        return redirect()->to($authService->redirectRouteFor($user));
    }
}