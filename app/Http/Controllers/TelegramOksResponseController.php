<?php

namespace App\Http\Controllers;

use App\Models\TelegramScheduledMessageDelivery;
use App\Services\Telegram\OksControlSchedule;
use App\Services\Telegram\ScheduledControlTypes;
use App\Services\Telegram\TelegramOksControlCards;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class TelegramOksResponseController extends Controller
{
    public function show(Request $request, string $delivery, string $question, string $district, TelegramOksControlCards $cards): View
    {
        $delivery = TelegramScheduledMessageDelivery::query()->withOksTests()->findOrFail($delivery);
        abort_unless($cards->canAnswer($delivery, $question, $district, $request->user()), 403);

        return $this->page($delivery, $question, $district, $cards);
    }

    public function store(Request $request, string $delivery, string $question, string $district, TelegramOksControlCards $cards): View
    {
        $delivery = TelegramScheduledMessageDelivery::query()->withOksTests()->findOrFail($delivery);
        abort_unless($cards->canAnswer($delivery, $question, $district, $request->user()), 403);
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:420'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        $synced = $cards->save($delivery, $question, $district, $request->user(), 'note', trim($validated['note']), version: (int) $validated['version']);

        return $this->page($delivery, $question, $district, $cards, $synced);
    }

    private function page(TelegramScheduledMessageDelivery $delivery, string $key, string $district, TelegramOksControlCards $cards, ?bool $synced = null): View
    {
        return view('telegram.oks-response', [
            'title' => OksControlSchedule::LABELS[$delivery->control_type],
            'districtName' => ScheduledControlTypes::DISTRICTS[$district],
            'question' => OksControlSchedule::questions($delivery->control_type)[$key],
            'answer' => $cards->latestAnswer($delivery, $key, $district),
            'action' => route('oks.response.save', [$delivery, $key, $district]),
            'synced' => $synced,
        ]);
    }
}
