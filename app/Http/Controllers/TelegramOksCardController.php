<?php

namespace App\Http\Controllers;

use App\Models\TelegramScheduledMessageDelivery;
use App\Services\Telegram\OksControlSchedule;
use App\Services\Telegram\ScheduledControlTypes;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOksControlCards;
use App\Services\Telegram\TelegramOksRichCard;
use App\Services\Telegram\TelegramShiftOpening;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TelegramOksCardController extends Controller
{
    public function __construct(
        private readonly TelegramOksControlCards $cards,
        private readonly TelegramShiftOpening $opening,
    ) {}

    public function show(Request $request, string $delivery, ?string $district = null): View|RedirectResponse
    {
        $card = $this->findCard($delivery);
        $districts = $this->districts($request, $card);
        if ($district === null && count($districts) === 1) {
            return redirect()->route('oks.card.show', [$card, array_key_first($districts)]);
        }
        abort_unless($district === null || isset($districts[$district]), 403);

        return $this->page($card, $districts, $district);
    }

    public function store(Request $request, string $delivery, string $district, TelegramBotService $telegram): View
    {
        $card = $this->findCard($delivery);
        $districts = $this->districts($request, $card);
        if ($card->control_type === ScheduledControlTypes::SHIFT_OPENING) {
            abort_unless(isset(ScheduledControlTypes::DISTRICTS[$district]), 403);
            $validated = $request->validate(['answers.opening.choice' => ['required', Rule::in(['yes', 'no'])]]);
            [$saved, $synced] = $this->opening->mark($card, $district, $request->user(), $validated['answers']['opening']['choice'], $telegram);

            return $this->page($card, $this->opening->remainingDistricts($card), $district,
                $saved ? 'Отметка сохранена.' : 'Район уже отмечен.', $synced);
        }

        abort_unless(isset($districts[$district]), 403);
        $questions = $this->cards->formQuestions($card, $district);
        $rules = ['answers' => ['required', 'array:'.implode(',', array_keys($questions))]];
        foreach ($questions as $key => $question) {
            $field = 'answers.'.$key;
            $rules[$field.'.choice'] = ['required', Rule::in(array_keys($question['options']))];
            $rules[$field.'.version'] = ['required', 'integer', 'min:0'];
            $rules[$field.'.note'] = ['required_if:'.$field.'.choice,note', 'nullable', 'string', 'max:420'];
        }
        $validated = $request->validate($rules);
        $synced = $this->cards->saveChecklist($card, $district, $request->user(), $validated['answers']);

        return $this->page($card, $districts, $district, 'Ответ сохранён.', $synced);
    }

    private function findCard(string $id): TelegramScheduledMessageDelivery
    {
        $card = TelegramScheduledMessageDelivery::query()->withOksTests()->findOrFail($id);
        abort_unless(TelegramOksRichCard::enabled($card), 404);

        return $card;
    }

    private function districts(Request $request, TelegramScheduledMessageDelivery $card): array
    {
        if ($card->control_type === ScheduledControlTypes::SHIFT_OPENING) {
            abort_unless($this->opening->canMark($card, $request->user()), 403);

            return $this->opening->remainingDistricts($card);
        }

        $districts = $this->cards->districtsFor($card, $request->user());
        abort_if($districts === [], 403, 'У тебя нет назначенных районов для этого контроля.');

        return $districts;
    }

    private function page(TelegramScheduledMessageDelivery $card, array $districts, ?string $district, ?string $result = null, ?bool $synced = null): View
    {
        $opening = $card->control_type === ScheduledControlTypes::SHIFT_OPENING;
        $questions = match (true) {
            $district === null || $result !== null => [],
            $opening => ['opening' => ['title' => 'Есть уборки в этом районе?', 'options' => ['yes' => 'Есть', 'no' => 'Нет уборок'], 'selected' => null]],
            default => $this->cards->formQuestions($card, $district),
        };

        return view('telegram.oks-card', [
            'card' => $card,
            'title' => $opening ? 'Открытие смены' : OksControlSchedule::LABELS[$card->control_type],
            'districts' => $districts,
            'district' => $district,
            'districtName' => ScheduledControlTypes::DISTRICTS[$district] ?? null,
            'questions' => $questions,
            'priorReason' => $opening || $district === null || $result !== null ? null : $this->cards->priorReason($card, $district),
            'result' => $result,
            'synced' => $synced,
            'canChooseAnother' => array_diff_key($districts, $district === null ? [] : [$district => true]) !== [],
        ]);
    }
}
