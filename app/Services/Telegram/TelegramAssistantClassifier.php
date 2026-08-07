<?php

namespace App\Services\Telegram;

class TelegramAssistantClassifier
{
    public const CATEGORY_DAY_OFF = 'day_off';
    public const CATEGORY_VACATION = 'vacation';
    public const CATEGORY_SHIFT = 'shift_problem';
    public const CATEGORY_APARTMENT_REFUSAL = 'apartment_refusal';
    public const CATEGORY_LATE = 'late';
    public const CATEGORY_ILLNESS = 'illness';
    public const CATEGORY_SALARY = 'salary';
    public const CATEGORY_TRAINING = 'training';
    public const CATEGORY_TECHNICAL = 'technical';
    public const CATEGORY_OTHER = 'other';

    public function classify(?string $text): array
    {
        $normalized = mb_strtolower(trim((string) $text));

        $rules = [
            self::CATEGORY_ILLNESS => '/(боле|забол|температур|врач|плох(?:о|ая)\s+себя)/ui',
            self::CATEGORY_APARTMENT_REFUSAL => '/(отказ(?:ываюсь|\s+от)?\s+(?:от\s+)?квартир|квартир(?:у|а)\s+не\s+бер|не\s+беру\s+квартир)/ui',
            self::CATEGORY_DAY_OFF => '/(выходн|отгул|не\s+смогу\s+выйти|не\s+выйду)/ui',
            self::CATEGORY_VACATION => '/(отпуск|отпускн|каникул)/ui',
            self::CATEGORY_LATE => '/(опозда|задержива|задержк)/ui',
            self::CATEGORY_SALARY => '/(зарплат|аванс|выплат|оплат)/ui',
            self::CATEGORY_TRAINING => '/(обучен|тренинг|курс|урок)/ui',
            self::CATEGORY_TECHNICAL => '/(не\s+работает|ошибк|сломал|приложен|сайт|телефон)/ui',
            self::CATEGORY_SHIFT => '/(смен|график|подмен|не\s+смогу\s+выйти|не\s+выйду)/ui',
        ];

        foreach ($rules as $category => $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return [
                    'category' => $category,
                    'confidence' => 'high',
                    'sensitive' => in_array($category, [self::CATEGORY_ILLNESS, self::CATEGORY_SALARY], true),
                ];
            }
        }

        return [
            'category' => self::CATEGORY_OTHER,
            'confidence' => 'low',
            'sensitive' => false,
        ];
    }

    public function isActivated(array $message, ?string $botUsername = null): bool
    {
        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));

        if ($text === '') {
            return false;
        }

        if (preg_match('/^\/(?:assistant|help|обращение)(?:@[^\s]+)?\b/ui', $text) === 1) {
            return true;
        }

        if ($botUsername !== null && $botUsername !== '') {
            $username = ltrim($botUsername, '@');

            if (preg_match('/@' . preg_quote($username, '/') . '\b/ui', $text) === 1) {
                return true;
            }
        }

        if (data_get($message, 'reply_to_message.from.is_bot') === true) {
            return true;
        }

        $classification = $this->classify($text);

        return $classification['confidence'] === 'high';
    }

    public function label(string $category): string
    {
        return match ($category) {
            self::CATEGORY_DAY_OFF => 'выходной',
            self::CATEGORY_VACATION => 'отпуск',
            self::CATEGORY_SHIFT => 'проблема со сменой',
            self::CATEGORY_APARTMENT_REFUSAL => 'отказ от квартиры',
            self::CATEGORY_LATE => 'опоздание',
            self::CATEGORY_ILLNESS => 'болезнь',
            self::CATEGORY_SALARY => 'зарплата',
            self::CATEGORY_TRAINING => 'обучение',
            self::CATEGORY_TECHNICAL => 'техническая проблема',
            default => 'другое',
        };
    }

    public function clarificationQuestion(string $category): ?string
    {
        return match ($category) {
            self::CATEGORY_DAY_OFF => 'Уточните, пожалуйста: на какую дату нужен выходной?',
            self::CATEGORY_VACATION => 'Уточните, пожалуйста: какие даты отпуска вы планируете?',
            self::CATEGORY_SHIFT => 'Уточните, пожалуйста: вы точно не сможете выйти на смену?',
            self::CATEGORY_LATE => 'Уточните, пожалуйста: во сколько вы будете на смене?',
            self::CATEGORY_ILLNESS => 'Уточните, пожалуйста: вы точно не сможете выйти сегодня или завтра?',
            default => null,
        };
    }
}
