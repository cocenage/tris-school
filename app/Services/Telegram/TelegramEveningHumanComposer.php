<?php

namespace App\Services\Telegram;

use App\Models\TelegramMessage;
use Illuminate\Support\Collection;

class TelegramEveningHumanComposer
{
    public function __construct(
        private readonly TelegramOperationalEventLifecyclePolicy $lifecyclePolicy,
    ) {}

    /** @return array{include: bool, handled: bool, decision: 'omit'|'composed'|'raw_safe'|'technical_failure', summary: ?string, follow_up: ?string, resolution?: ?string, show_in_day?: bool} */
    public function compose(array $item): array
    {
        $rawSummary = (string) ($item['summary'] ?? '');
        $summary = $this->clean($rawSummary);
        $evidence = $this->evidenceTexts($item);
        $context = $evidence->concat([$summary])->filter()->unique()->implode(' ');
        $types = collect($item['types'] ?? []);
        $isOpen = in_array($item['status'] ?? null, ['open', 'reopened'], true);
        $actor = $this->clean((string) ($item['actor_name'] ?? ''));

        if ($summary === '' && $evidence->isEmpty()) {
            return $this->omit();
        }

        // A preview item with no evidence references cannot be semantically
        // judged. Preserve the legacy formatter only for this incomplete input.
        if (collect($item['evidence'] ?? [])->pluck('local_message_id')->filter(fn (mixed $id): bool => is_numeric($id))->isEmpty()) {
            return [
                'include' => true,
                'handled' => false,
                'decision' => 'technical_failure',
                'summary' => null,
                'follow_up' => null,
            ];
        }

        if ($types->contains('positive_contribution')
            && $types->diff(['positive_contribution'])->isEmpty()
            && $summary !== '') {
            return $this->result(mb_ucfirst(rtrim($summary, " .!?\t\n\r\0\x0B")).'.', null);
        }

        if ($this->lifecyclePolicy->isStandaloneInstruction($summary)
            && $evidence->every(fn (string $text): bool => $this->lifecyclePolicy->isStandaloneInstruction($text))) {
            return $this->omit();
        }

        if ($this->isDirtyReferentFragment($summary)) {
            $objectSummary = $this->dirtyReferentSummary($evidence->implode(' '));

            if ($objectSummary === null) {
                return $this->omit();
            }

            return $this->result($objectSummary, null);
        }

        if (($item['status'] ?? null) === 'resolved' && preg_match('/закрыли\s+двер/iu', $context) === 1) {
            return $this->result('Дверь закрыли.', null, 'Дверь закрыли.', false);
        }

        if ($this->isAccessIssue($context)) {
            $detail = preg_match('/консьерж/iu', $context) === 1
                ? 'Возникла проблема с доступом: консьержа не было на месте, дверь не открывали.'
                : 'Возникла проблема с доступом.';

            return $this->result($detail, $isOpen ? 'Проверить, решён ли вопрос с доступом в квартиру.' : null);
        }

        if ($this->isLinenCourierIssue($context)) {
            $broughtClean = preg_match('/(?:прив[её]з|прин[её]с).{0,50}(?:чист|бель)|(?:чист|бель).{0,50}(?:прив[её]з|прин[её]с)/iu', $context) === 1;
            $didNotTakeDirty = preg_match('/(?:не\s+забрал|забрал\s+не\s+вс[её]).{0,60}(?:гряз|бель)|(?:гряз|бель).{0,60}(?:не\s+забрал|забрал\s+не\s+вс[её])/iu', $context) === 1;
            $mentionsDirty = preg_match('/гряз/iu', $context) === 1;

            if ($broughtClean && $didNotTakeDirty) {
                return $this->result(
                    'Курьер привёз чистое бельё, но не забрал грязное.',
                    $isOpen ? 'Уточнить, когда курьер заберёт грязное бельё.' : null,
                );
            }

            if ($didNotTakeDirty) {
                return $this->result(
                    $mentionsDirty ? 'Курьер забрал не всё грязное бельё.' : 'Курьер забрал не всё бельё.',
                    $isOpen
                        ? ($mentionsDirty
                            ? 'Уточнить, когда курьер заберёт оставшееся грязное бельё.'
                            : 'Уточнить, когда курьер заберёт оставшееся бельё.')
                        : null,
                );
            }
        }

        if (preg_match('/гостев.{0,20}локер|локер.{0,20}гостев/iu', $context) === 1
            && preg_match('/программ/iu', $context) === 1
            && preg_match('/ошиб|неверн/iu', $context) === 1
            && preg_match('/(?:должен\s+быть|правильн\S*\s+код)\D{0,10}(\d{3,8})/iu', $context, $matches) === 1) {
            return $this->result(
                'В программе указан неверный код гостевого локера; правильный код — '.$matches[1].'.',
                $isOpen ? 'Исправить код гостевого локера в программе.' : null,
            );
        }

        if (preg_match('/гост\S*\s+забыл\S*.{0,40}конверт|конверт.{0,40}забыл/iu', $context) === 1) {
            return $this->result(
                'Гость забыл конверт; нужно найти его и сообщить о находке.',
                $isOpen ? 'Найти конверт и сообщить о находке.' : null,
            );
        }

        if (preg_match('/посудомоеч/iu', $context) === 1
            && preg_match('/вытек|теч|вод/iu', $context) === 1) {
            return $this->result(
                'Из посудомоечной машины вытекала вода; нужно проверить её состояние.',
                $isOpen ? 'Проверить состояние посудомоечной машины.' : null,
            );
        }

        if (preg_match('/(?:\bпосуд[ауыое]\b.{0,30}грязн|грязн.{0,30}\bпосуд[ауыое]\b)/iu', $context) === 1) {
            return $this->result(
                preg_match('/вся\s+посуда\s+грязн/iu', $context) === 1
                    ? 'Вся посуда была грязной.'
                    : 'Обнаружена грязная посуда.',
                null,
            );
        }

        if ($types->contains('delay')) {
            return $this->result($this->delaySummary($context, $actor), null);
        }

        if (preg_match('/ручк/iu', $context) === 1
            && preg_match('/отвал|слом/iu', $context) === 1
            && preg_match('/окн|двер/iu', $context) === 1) {
            $label = preg_match('/окн/iu', $context) === 1 ? 'окна' : 'двери';

            return $this->result(
                'Отвалилась ручка '.$label.'.',
                $isOpen ? 'Проверить крепление ручки '.$label.'.' : null,
            );
        }

        if ($this->isLightIssue($context)) {
            if (preg_match('/комнат\S*\s*(\d+)/iu', $context, $room) === 1) {
                $detail = 'Не работает свет в комнате '.$room[1].'.';
                $followUp = 'Проверить неисправность света в комнате '.$room[1].'.';
            } elseif (preg_match('/вытяж/iu', $context) === 1) {
                $detail = 'У вытяжки не работает свет.';
                $followUp = 'Проверить свет у вытяжки.';
            } else {
                $detail = 'Не работает свет.';
                $followUp = 'Проверить неисправность света.';
            }

            return $this->result(
                $detail,
                $isOpen ? $followUp : null,
            );
        }

        if (preg_match('/вытяжк/iu', $context) === 1
            && preg_match('/не\s+работает|слом/iu', $context) === 1) {
            return $this->result(
                'На кухне не работает вытяжка.',
                $isOpen ? 'Проверить, работает ли вытяжка на кухне.' : null,
            );
        }

        if (preg_match('/пульт/iu', $context) === 1
            && preg_match('/кондиционер|конд[её]р/iu', $context) === 1
            && preg_match('/не\s+работает|слом/iu', $context) === 1) {
            return $this->result(
                'Не работает пульт кондиционера.',
                $isOpen ? 'Проверить пульт кондиционера.' : null,
            );
        }

        if (preg_match('/договорил\S*.{0,35}выезд\S*\s+позже|выезд\S*\s+позже/iu', $context) === 1) {
            return $this->result('Гости договорились о более позднем выезде.', null);
        }

        if ($this->isKitchenDustIssue($context)) {
            return $this->result('Гости сообщили о грязи на кухне и пыли.', null);
        }

        if ($this->isApartmentQualityReview($context)) {
            return $this->result('В квартире отметили пыль на светильнике и небольшие недочёты на кухне и в ванной.', null);
        }

        if ($this->isLinenDefect($context)) {
            $objects = collect([
                preg_match('/полотен/iu', $context) === 1 ? 'полотенца' : null,
                preg_match('/пододеяльник/iu', $context) === 1 ? 'пододеяльника' : null,
            ])->filter()->values();

            if ($objects->isNotEmpty()) {
                return $this->result('Обнаружен брак '.$objects->join(' и ').'.', null);
            }
        }

        if (preg_match('/коврик/iu', $context) === 1 && preg_match('/брак/iu', $context) === 1) {
            return $this->result('Обнаружен брак коврика.', null);
        }

        if ($this->isConcreteQuestion($context)) {
            return $this->question($context, $isOpen);
        }

        if ($this->isInstructionOrRoutine($context)) {
            return $this->omit();
        }

        if ($this->isMeaninglessFragment($summary)
            && $evidence->every(fn (string $text): bool => $this->isMeaninglessFragment($text))) {
            return $this->omit();
        }

        if ($types->intersect(['request', 'unanswered_question'])->isNotEmpty()
            && (str_contains($context, '?') || $this->isConversationalQuestion($context))) {
            return $this->omit();
        }

        if ($summary !== '' && $this->startsWithAcknowledgementFraming($rawSummary)) {
            return $this->result(mb_ucfirst(rtrim($summary, " .!?\t\n\r\0\x0B")).'.', null);
        }

        if ($this->isRawSafe($summary, $types->all())) {
            return [
                'include' => true,
                'handled' => false,
                'decision' => 'raw_safe',
                'summary' => null,
                'follow_up' => null,
            ];
        }

        return $this->omit();
    }

    /** @return array{include: true, handled: true, decision: 'composed', summary: string, follow_up: ?string, resolution: ?string, show_in_day: bool} */
    private function result(
        string $summary,
        ?string $followUp,
        ?string $resolution = null,
        bool $showInDay = true,
    ): array {
        return [
            'include' => true,
            'handled' => true,
            'decision' => 'composed',
            'summary' => $summary,
            'follow_up' => $followUp,
            'resolution' => $resolution,
            'show_in_day' => $showInDay,
        ];
    }

    /** @return array{include: true, handled: true, decision: 'composed', summary: string, follow_up: ?string} */
    private function question(string $context, bool $isOpen): array
    {
        if (preg_match('/во\s+сколько.{0,40}заезд|заезд.{0,40}во\s+сколько/iu', $context) === 1) {
            return $this->result(
                'Уточняли время заезда.',
                $isOpen ? 'Уточнить время заезда.' : null,
            );
        }

        if (preg_match('/диван/iu', $context) === 1 && preg_match('/постельн|бель[еёя]/iu', $context) === 1) {
            return $this->result(
                'Уточняли наличие постельного белья для дивана.',
                $isOpen ? 'Уточнить наличие постельного белья для дивана.' : null,
            );
        }

        if (preg_match('/очк/iu', $context) === 1 && preg_match('/слом|поврежд/iu', $context) === 1) {
            return $this->result(
                'Уточняли, что делать со сломанными очками.',
                $isOpen ? 'Уточнить, нужно ли выбрасывать сломанные очки.' : null,
            );
        }

        if (preg_match('/запасн.{0,25}бумаг|бумаг.{0,25}запасн/iu', $context) === 1) {
            return $this->result(
                'Уточняли наличие запасной бумаги.',
                $isOpen ? 'Проверить наличие запасной бумаги.' : null,
            );
        }

        if (preg_match('/(?:два|2)\s+пульт/iu', $context) === 1) {
            return $this->result(
                'Уточняли наличие второго пульта.',
                $isOpen ? 'Уточнить наличие второго пульта.' : null,
            );
        }

        if (preg_match('/комплект/iu', $context) === 1 && preg_match('/программ/iu', $context) === 1) {
            return $this->result(
                'Уточняли, как отметить комплект в программе.',
                $isOpen ? 'Уточнить, как отметить комплект в программе.' : null,
            );
        }

        if (preg_match('/сколько.{0,20}времени|времени.{0,20}сколько/iu', $context) === 1) {
            return $this->result(
                'Уточняли, сколько времени потребуется.',
                $isOpen ? 'Уточнить, сколько времени потребуется.' : null,
            );
        }

        if ($this->isBlanketQuestion($context)) {
            return $this->result(
                'Уточняли наличие одеял в квартире.',
                $isOpen ? 'Уточнить наличие одеял в квартире.' : null,
            );
        }

        return $this->omit();
    }

    /** @return array{include: false, handled: true, decision: 'omit', summary: null, follow_up: null} */
    private function omit(): array
    {
        return [
            'include' => false,
            'handled' => true,
            'decision' => 'omit',
            'summary' => null,
            'follow_up' => null,
        ];
    }

    /** @param array<int, string> $types */
    private function isRawSafe(string $summary, array $types): bool
    {
        if ($summary === '' || $this->isMeaninglessFragment($summary)
            || collect($types)->intersect(['problem', 'quality_issue', 'risk', 'request'])->isEmpty()) {
            return false;
        }

        if (preg_match('/(?:подключиться.{0,50}поэтому\s+так\s+отправля|поэтому\s+так\s+отправля)/iu', $summary) === 1) {
            return false;
        }

        return preg_match('/жалюз.{0,70}(?:упал\S*.{0,60}(?:не\s+могу\s+повесить|высок)|не\s+могу\s+повесить.{0,60}высок)|(?:простын|полотен|пододеял).{0,90}(?:пятн|брак|гряз|замен\S*\s+нет)|(?:пятн|брак|гряз|замен\S*\s+нет).{0,90}(?:простын|полотен|пододеял)|слом\S*.{0,35}вешалк|вешалк.{0,35}слом|(?:не\s+могу\s+найти|не\s+нашл|отсутств|нет\b).{0,60}(?:запасн.{0,20}бумаг|ключ|полотен|бель|пульт|вешалк|инвентар|средств)/iu', $summary) === 1;
    }

    /** @return Collection<int, string> */
    private function evidenceTexts(array $item): Collection
    {
        $ids = collect($item['evidence'] ?? [])
            ->pluck('local_message_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->take(8)
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $messages = TelegramMessage::query()
            ->whereKey($ids)
            ->get(['id', 'text', 'caption'])
            ->keyBy('id');

        return $ids->map(function (mixed $id) use ($messages): string {
            $message = $messages->get((int) $id);

            return $this->clean(mb_strimwidth((string) ($message?->text ?: $message?->caption ?: ''), 0, 500, ''));
        })->filter()->values();
    }

    private function clean(string $text): string
    {
        $text = preg_replace('/(?:^|\s)@[\p{L}\p{N}_]+\b/u', '', strip_tags($text)) ?: '';
        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $text) ?: $text;
        $text = preg_replace('/^(?:привет|здравствуйте|девочки|коллеги)[,!\.\s]+/iu', '', trim($text)) ?: $text;
        $text = preg_replace('/^[\p{Lu}][\p{Ll}]{2,20}[,!:]\s*/u', '', $text) ?: $text;
        $text = preg_replace('/^(?:(?:да|хорошо|понял(?:а)?|спасибо|ок(?:ей)?)[,;.!?)\s]*)+/iu', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
    }

    private function isAccessIssue(string $text): bool
    {
        return preg_match('/(?:двер|доступ|консьерж|открыть\s+удал[её]нно)/iu', $text) === 1
            && preg_match('/(?:не\s+открыва|не\s+открывают|закрыт|консьерж|доступ|открыть\s+удал[её]нно)/iu', $text) === 1;
    }

    private function isLinenCourierIssue(string $text): bool
    {
        return preg_match('/курьер/iu', $text) === 1 && preg_match('/бель|грязн|чист/iu', $text) === 1;
    }

    private function isLightIssue(string $text): bool
    {
        return preg_match('/(?:свет|подсвет)/iu', $text) === 1
            && preg_match('/не\s+работает|не\s+включа|слом/iu', $text) === 1;
    }

    private function isKitchenDustIssue(string $text): bool
    {
        return preg_match('/(?:гост|отзыв)/iu', $text) === 1
            && preg_match('/кухн/iu', $text) === 1
            && preg_match('/гряз|пыл/iu', $text) === 1;
    }

    private function isLinenDefect(string $text): bool
    {
        return preg_match('/(?:брак|слом|поврежд)/iu', $text) === 1
            && preg_match('/полотен|пододеяльник/iu', $text) === 1;
    }

    private function isApartmentQualityReview(string $text): bool
    {
        return preg_match('/пыл.{0,40}светильник|светильник.{0,40}пыл/iu', $text) === 1
            && preg_match('/кухн/iu', $text) === 1
            && preg_match('/ванн/iu', $text) === 1
            && preg_match('/недоч[её]т|гряз/iu', $text) === 1;
    }

    private function isConcreteQuestion(string $text): bool
    {
        return preg_match('/(?:во\s+сколько.{0,40}заезд|заезд.{0,40}во\s+сколько|диван.{0,50}(?:постельн|бель)|очк.{0,50}(?:слом|поврежд)|запасн.{0,25}бумаг|бумаг.{0,25}запасн|(?:два|2)\s+пульт|комплект.{0,80}программ|программ.{0,80}комплект|сколько.{0,20}времени|времени.{0,20}сколько|одеял.{0,50}(?:есть|где|сколько|налич)|(?:есть|где|сколько|налич).{0,50}одеял)/iu', $text) === 1;
    }

    private function isConversationalQuestion(string $text): bool
    {
        return str_contains($text, '?')
            && preg_match('/(?:это\s+гости\s+или\s+ты|и\s+сколько\s+осталось|я\s+не\s+понимаю|это\s+с\s+.+\s+или\s+они|как\s+я\s+помню)/iu', $text) === 1;
    }

    private function isMeaninglessFragment(string $text): bool
    {
        $normalized = mb_strtolower(trim($text, " \t\n\r\0\x0B.!?(),"));

        return $normalized === ''
            || preg_match('/^(?:сломана|сломано|это\s+ошибка|есть\s+грязные\s+моменты|хорошо|поняла|понял|спасибо|ок)$/iu', $normalized) === 1
            || preg_match('/^(?:(?:хорошо|поняла|понял|спасибо|ок)[,.\s]*)+$/iu', $normalized) === 1
            || preg_match('/^они\s+(?:вообще\s+)?не\s+открыва\S*(?:\s+почему-то)?$/iu', $normalized) === 1
            || preg_match('/не\s+знаю.{0,40}было\s+ли.{0,30}сломан.{0,30}раньше/iu', $normalized) === 1
            || preg_match('/^(?:отмечен\s+риск:\s*)?на\s+более\s+быстрый$/iu', $normalized) === 1;
    }

    private function isDirtyReferentFragment(string $text): bool
    {
        return preg_match('/(?:^|[—–-]\s*)нет[\s.…,-]*это\s+грязн(?:ое|ая|ый|ые)[.!?]*$/iu', trim($text)) === 1;
    }

    private function dirtyReferentSummary(string $evidence): ?string
    {
        if (preg_match('/постельн.{0,35}владельц|владельц.{0,35}постельн/iu', $evidence) === 1) {
            return 'Постельное бельё владельца оказалось грязным.';
        }

        foreach ([
            '/постельн|бель[еёя]/iu' => 'Постельное бельё оказалось грязным.',
            '/полотен/iu' => 'Полотенце оказалось грязным.',
            '/посуд/iu' => 'Посуда оказалась грязной.',
            '/одеял/iu' => 'Одеяло оказалось грязным.',
        ] as $pattern => $object) {
            if (preg_match($pattern, $evidence) === 1) {
                return $object;
            }
        }

        return null;
    }

    private function startsWithAcknowledgementFraming(string $text): bool
    {
        return preg_match('/^\s*(?:да|хорошо|понял(?:а)?|спасибо|ок(?:ей)?)(?:\b|[,.!?)])/iu', $text) === 1;
    }

    private function delaySummary(string $context, string $actor): string
    {
        if (preg_match('/(?:следующ|втор).{0,35}квартир|квартир.{0,35}(?:следующ|втор)/iu', $context) === 1
            && preg_match('/немного|небольш|чуть/iu', $context) === 1) {
            return $actor !== ''
                ? $actor.' задерживается перед следующей квартирой.'
                : 'Сотрудник предупредил о небольшой задержке перед следующей квартирой.';
        }

        if (preg_match('/(?:на|через|примерно)?\s*(\d{1,3})\s*мин/iu', $context, $matches) === 1) {
            return $actor !== ''
                ? $actor.' задерживается примерно на '.(int) $matches[1].' минут.'
                : 'Сотрудник сообщил о задержке примерно на '.(int) $matches[1].' минут.';
        }

        return $actor !== '' ? $actor.' задерживается.' : 'Сотрудник сообщил о задержке.';
    }

    private function isBlanketQuestion(string $text): bool
    {
        return preg_match('/одеял/iu', $text) === 1
            && preg_match('/\?|есть|где|сколько|налич/iu', $text) === 1;
    }

    private function isInstructionOrRoutine(string $text): bool
    {
        return preg_match(
            '/(?:я\s+возьму\s+с\s+нового\s+комплект.{0,30}сделаю\s+его\s+грязн|мне\s+же\s+не\s+нужно.{0,20}ждать|когда\s+я\s+была\s+уже\s+на\s+другой\s+квартире|скача\S*\s+видео|загруз\S*.{0,30}сайт|закр\S*.{0,20}уборк|одеял.{0,30}(?:возьми|положи|разложи)|(?:возьми|положи|разложи).{0,30}одеял)/iu',
            $text,
        ) === 1;
    }
}
