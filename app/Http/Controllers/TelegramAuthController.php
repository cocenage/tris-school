<?php

namespace App\Http\Controllers;

use App\Models\ControlResponse;
use App\Models\TelegramScheduledMessageDelivery;
use App\Models\User;
use App\Services\Telegram\TelegramOksControlCards;
use App\Services\Telegram\TelegramOksRichCard;
use App\Services\Telegram\TelegramShiftOpening;
use App\Services\TelegramAuthService;
use App\Services\TelegramMiniAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TelegramAuthController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramMiniAppService $miniAppService,
        TelegramAuthService $authService
    ): JsonResponse {
        $validated = $request->validate([
            'init_data' => ['required', 'string'],
        ]);

        $telegramUser = $miniAppService->validate($validated['init_data']);

        $startParam = (string) ($telegramUser['start_param'] ?? '');
        if (preg_match('/^oks_card_([1-9][0-9]*)$/D', $startParam, $match)) {
            $delivery = TelegramScheduledMessageDelivery::query()->withOksTests()->find($match[1]);
            $user = User::query()->activeStaff()->where('telegram_id', $telegramUser['telegram_id'])->first();
            abort_unless($delivery && $user && TelegramOksRichCard::enabled($delivery)
                && (app(TelegramShiftOpening::class)->canMark($delivery, $user)
                    || app(TelegramOksControlCards::class)->districtsFor($delivery, $user) !== []), 403);
            Auth::login($user);
            $request->session()->regenerate();
            $redirect = route('oks.card.show', $delivery);
        } elseif (str_starts_with($startParam, 'oks_')) {
            abort_unless(preg_match('/^oks_([1-9][0-9]*)_(check|start|late|next)_([a-z]+)$/D', $startParam, $match), 403);
            $delivery = TelegramScheduledMessageDelivery::query()->withOksTests()->find((int) $match[1]);
            $user = User::query()->activeStaff()->where('telegram_id', $telegramUser['telegram_id'])->first();
            abort_unless($delivery && $user && app(TelegramOksControlCards::class)->canAnswer($delivery, $match[2], $match[3], $user), 403);
            Auth::login($user);
            $request->session()->regenerate();
            $redirect = route('oks.response.show', [$delivery, $match[2], $match[3]]);
        } else {
            $user = $authService->loginOrCreate($telegramUser);
            $redirect = $authService->redirectRouteFor($user);
        }

        if ($startParam === 'antigoccia' && $user->status === 'approved' && $user->is_active) {
            $redirect = route('antigoccia.index');
        }

        if (preg_match('/^control_result_([1-9][0-9]*)$/D', $startParam, $match)) {
            abort_unless($user->status === 'approved' && $user->is_active
                && in_array($user->role, ['cleaner', 'supervisor', 'admin'], true), 403);

            $controlResponse = ControlResponse::query()
                ->whereKey((int) $match[1])
                ->where('status', ControlResponse::STATUS_SENT)
                ->where(fn ($query) => $query
                    ->where('cleaner_id', $user->id)
                    ->orWhere('supervisor_id', $user->id))
                ->first();

            abort_unless($controlResponse, 403);
            $redirect = route('page-profile.checks.result', $controlResponse);
        }

        return response()->json([
            'ok' => true,
            'redirect' => $redirect,
            'user' => [
                'id' => $user->id,
                'telegram_id' => $user->telegram_id,
                'status' => $user->status,
            ],
        ]);
    }
}
