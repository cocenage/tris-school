<?php

namespace App\Services\Telegram;

use Illuminate\Support\Str;

class TelegramOperationalInterpreter
{
    private const UNCERTAIN_PATTERN = '/\b(кажется|возможно|может\s+быть|наверн|похоже|вероятно)\b/ui';

    public function __construct(
        protected TelegramAssistantClassifier $assistantClassifier,
    ) {}

    public function interpret(?string $text): array
    {
        $text = trim((string) $text);
        $normalized = mb_strtolower($text);
        $assistant = $this->assistantClassifier->classify($text);

        if ($text === '') {
            return $this->noEvent('empty_content', $assistant['category']);
        }

        $isQuestion = str_contains($text, '?')
            || preg_match('/^(кто|что|где|когда|как|почему|можно\s+ли|есть\s+ли)\b/ui', $normalized) === 1;
        $isUncertain = preg_match(self::UNCERTAIN_PATTERN, $normalized) === 1;

        $signal = $this->detectSignal($normalized, $assistant['category'], $isQuestion);

        if ($signal === null) {
            return $this->noEvent('ordinary_conversation', $assistant['category'], $isQuestion);
        }

        if ($signal['type'] === 'request' && ! $this->hasOperationalContext($normalized)) {
            if (str_word_count($normalized, 0, 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя') < 8) {
                return $this->noEvent('insufficient_operational_signal', $assistant['category'], $isQuestion);
            }

            $signal['confidence'] = 'low';
            $signal['uncertainty'] = 'Недостаточно операционного контекста для уверенной интерпретации запроса.';
        }

        if ($signal['type'] === 'delay' && ! $this->hasOperationalContext($normalized)) {
            $signal['confidence'] = 'medium';
        }

        if ($signal['type'] === 'action' && ! $this->hasOperationalContext($normalized)) {
            $signal['confidence'] = 'medium';
        }

        $confidence = $isUncertain ? 'low' : $signal['confidence'];
        $uncertainty = $isUncertain
            ? 'Сообщение сформулировано как предположение; требуется подтверждение.'
            : ($signal['uncertainty'] ?? null);

        return [
            'meaningful' => true,
            'reason_code' => $signal['reason_code'],
            'primary_type' => $signal['type'],
            'types' => [$signal['type']],
            'role' => $signal['role'],
            'transition' => $isUncertain && $signal['transition'] === 'resolved'
                ? 'none'
                : $signal['transition'],
            'summary' => $this->summary($text),
            'confidence' => $confidence,
            'uncertainty' => $uncertainty,
            'subject_key' => $this->subjectKey($normalized),
            'is_question' => $isQuestion,
            'assistant_category' => $assistant['category'],
        ];
    }

    private function detectSignal(string $text, string $assistantCategory, bool $isQuestion): ?array
    {
        $signals = [
            [
                'pattern' => '/(исправ(?:лен|или|лено)|решен[оа]?|решили|готово|закрыли|починили|устранили|вопрос\s+закрыт)/ui',
                'type' => 'resolution',
                'role' => 'resolution',
                'transition' => 'resolved',
                'reason_code' => 'operational_resolution',
                'confidence' => 'high',
            ],
            [
                'pattern' => '/(плохо\s+убран|гряз|качество|брак|недоч[её]т|некачествен|вонь|запах\s+канализац|подт[её]к|протеч|мусорин|в\s+разводах|остал(?:ись|ось)?.{0,20}развод)/ui',
                'type' => 'quality_issue',
                'role' => 'report',
                'transition' => 'created',
                'reason_code' => 'quality_issue',
                'confidence' => 'high',
            ],
            [
                'pattern' => '/(?:(?:спасибо|молодец|отлично|здорово).{0,80}(?:помог|сделал|исправ|решил|убрал|привез|забрал|проверил|закрыл|подменил|выручил)|(?:помог|сделал|исправ|решил|убрал|привез|забрал|проверил|закрыл|подменил|выручил).{0,80}(?:спасибо|молодец|отлично|здорово))/ui',
                'type' => 'positive_contribution',
                'role' => 'positive',
                'transition' => 'created',
                'reason_code' => 'positive_contribution',
                'confidence' => 'high',
            ],
            [
                'pattern' => '/(обещаю|я\s+(?:сам[а]?\s+)?(?:сделаю|привезу|заберу|проверю|исправлю)|беру\s+(?:на\s+себя|в\s+работу)|буду\s+(?:делать|проверять|исправлять))/ui',
                'type' => 'commitment',
                'role' => 'commitment',
                'transition' => 'updated',
                'reason_code' => 'operational_commitment',
                'confidence' => 'high',
            ],
            [
                'pattern' => '/(уже\s+(?:проверяю|делаю|исправляю|еду|занимаюсь)|начал[аи]?|проверяем|в\s+работе|взял[аи]?\s+в\s+работу)/ui',
                'type' => 'action',
                'role' => 'action',
                'transition' => 'updated',
                'reason_code' => 'operational_action',
                'confidence' => 'high',
            ],
            [
                'pattern' => '/(опозд|задерж|не\s+успе|позже|перенос)/ui',
                'type' => 'delay',
                'role' => 'report',
                'transition' => 'created',
                'reason_code' => 'operational_delay',
                'confidence' => 'high',
            ],
            [
                'pattern' => '/(риск|опасн|может\s+(?:сорваться|не\s+успеть|не\s+попасть)|угроз)/ui',
                'type' => 'risk',
                'role' => 'report',
                'transition' => 'created',
                'reason_code' => 'operational_risk',
                'confidence' => 'high',
            ],
            [
                'pattern' => '/(пожалуйста|нужно|необходимо|прошу|требуется|кто\s+(?:может|привез|забер|сдела)|можно\s+ли)/ui',
                'type' => 'request',
                'role' => $isQuestion ? 'question' : 'request',
                'transition' => 'created',
                'reason_code' => $isQuestion ? 'operational_question' : 'operational_request',
                'confidence' => 'high',
            ],
            [
                'pattern' => '/(проблем|не\s+работает|слом|нет\s+ключ|не\s+откры|ошибк|не\s+могу)/ui',
                'type' => 'problem',
                'role' => 'report',
                'transition' => 'created',
                'reason_code' => 'operational_problem',
                'confidence' => 'high',
            ],
        ];

        foreach ($signals as $signal) {
            if (preg_match($signal['pattern'], $text) === 1) {
                return $signal;
            }
        }

        if ($assistantCategory !== TelegramAssistantClassifier::CATEGORY_OTHER) {
            return $this->assistantSignal($assistantCategory, $isQuestion);
        }

        if ($isQuestion && preg_match('/(ключ|квартир|замок|уборк|смен|гост|заезд|график|оплат)/ui', $text) === 1) {
            return [
                'type' => 'request',
                'role' => 'question',
                'transition' => 'created',
                'reason_code' => 'operational_question',
                'confidence' => 'high',
            ];
        }

        return null;
    }

    private function assistantSignal(string $category, bool $isQuestion): array
    {
        $type = match ($category) {
            TelegramAssistantClassifier::CATEGORY_LATE => 'delay',
            TelegramAssistantClassifier::CATEGORY_ILLNESS => 'risk',
            TelegramAssistantClassifier::CATEGORY_TECHNICAL => 'problem',
            default => 'request',
        };

        return [
            'type' => $type,
            'role' => $isQuestion ? 'question' : ($type === 'request' ? 'request' : 'report'),
            'transition' => 'created',
            'reason_code' => $isQuestion ? 'operational_question' : 'assistant_category_signal',
            'confidence' => 'medium',
        ];
    }

    private function noEvent(string $reasonCode, string $assistantCategory, bool $isQuestion = false): array
    {
        return [
            'meaningful' => false,
            'reason_code' => $reasonCode,
            'primary_type' => null,
            'types' => [],
            'role' => null,
            'transition' => 'none',
            'summary' => null,
            'confidence' => null,
            'uncertainty' => null,
            'subject_key' => null,
            'is_question' => $isQuestion,
            'assistant_category' => $assistantCategory,
        ];
    }

    private function summary(string $text): string
    {
        $text = preg_replace('/https?:\/\/\S+/ui', '', strip_tags($text));
        $text = preg_replace('/\s+/u', ' ', (string) $text);

        return Str::limit(trim((string) $text), 240, '…');
    }

    private function subjectKey(string $text): ?string
    {
        $subjects = [
            'lock' => '/(замок|двер)/ui',
            'keys' => '/ключ/ui',
            'apartment' => '/(квартир|апартамент)/ui',
            'cleaning' => '/(уборк|гряз|качеств)/ui',
            'shift' => '/(смен|график)/ui',
            'guest' => '/(гост|клиент|заезд)/ui',
            'payment' => '/(зарплат|оплат|аванс)/ui',
            'technical' => '/(сайт|приложен|телефон)/ui',
        ];

        $matched = [];

        foreach ($subjects as $key => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $matched[] = $key;
            }
        }

        if ($matched !== []) {
            sort($matched);

            return implode('|', $matched);
        }

        return null;
    }

    private function hasOperationalContext(string $text): bool
    {
        return preg_match(
            '/(замок|ключ|квартир|апартамент|уборк|смен|гост|заезд|выезд|график|оплат|двер|пульт|таймер|кондиционер|полотен|бель|капсул|бумаг|мусор|духовк|ванн|кухн|кроват|шкаф|свет|вод|канализац|труб|стирк)/ui',
            $text,
        ) === 1;
    }
}
