<?php

namespace App\Filament\Resources\ControlResponses\Schemas;

use App\Models\ControlResponse;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ControlResponseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основная информация')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('control.name')
                            ->label('Форма контроля'),

                        TextEntry::make('cleaner.name')
                            ->label('Кого проверили'),

                        TextEntry::make('supervisor.name')
                            ->label('Кто проверил'),

                        TextEntry::make('apartment.name')
                            ->label('Квартира'),

                        TextEntry::make('cleaning_date')
                            ->label('Дата уборки')
                            ->date('d.m.Y'),

                        TextEntry::make('inspection_date')
                            ->label('Дата проверки')
                            ->date('d.m.Y'),

                        TextEntry::make('sent_at')
                            ->label('Отправлено')
                            ->dateTime('d.m.Y H:i'),

                        TextEntry::make('points')
                            ->label('Баллы')
                            ->state(fn (ControlResponse $record) => "{$record->total_points}/{$record->max_points}"),

                        TextEntry::make('score_percent')
                            ->label('Процент')
                            ->suffix('%'),

                        TextEntry::make('result_zone_label')
                            ->label('Зона'),

                        TextEntry::make('has_critical_failure')
                            ->label('Критическая ошибка')
                            ->formatStateUsing(fn ($state) => $state ? 'Да' : 'Нет'),

                        TextEntry::make('comment')
                            ->label('Комментарий')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Ответы')
                    ->schema([
                        TextEntry::make('answers_rendered')
                            ->label('')
                            ->state(fn (ControlResponse $record) => self::renderAnswers($record))
                            ->html()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected static function renderAnswers(ControlResponse $record): string
    {
        $schema = is_array($record->schema_snapshot) ? $record->schema_snapshot : [];
        $responses = is_array($record->responses) ? $record->responses : [];

        if (empty($schema)) {
            return '<div style="color:#777;">Нет данных</div>';
        }

        $html = '<div style="display:flex;flex-direction:column;gap:16px;">';

        foreach ($schema as $roomIndex => $room) {
            $roomTitle = e($room['title'] ?? ('Комната ' . ((int) $roomIndex + 1)));

            $html .= '<div style="border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;background:#fff;">';
            $html .= '<div style="background:#f3f4f6;padding:14px 16px;font-weight:700;">' . $roomTitle . '</div>';
            $html .= '<div style="padding:14px 16px;display:flex;flex-direction:column;gap:12px;">';

            foreach (($room['items'] ?? []) as $questionIndex => $question) {
                $questionText = e($question['question'] ?? 'Вопрос');
                $answer = $responses[$roomIndex][$questionIndex] ?? [];

                $selected = trim((string) ($answer['selected'] ?? ''));
                $custom = trim((string) ($answer['custom'] ?? ''));

                $answerText = self::resolveAnswerText($question, $selected, $custom);

                $html .= '<div style="border-bottom:1px solid #f1f1f1;padding-bottom:10px;">';
                $html .= '<div style="font-size:13px;color:#6b7280;margin-bottom:4px;">' . $questionText . '</div>';
                $html .= '<div style="font-size:15px;font-weight:600;color:#111827;">' . e($answerText) . '</div>';
                $html .= '</div>';
            }

            $html .= '</div></div>';
        }

        $html .= '</div>';

        return $html;
    }

    protected static function resolveAnswerText(array $question, string $selected, string $custom): string
    {
        $label = $selected;

        foreach (($question['answer_options_scored'] ?? []) as $optIndex => $opt) {
            $value = trim((string) ($opt['value'] ?? ('option_' . $optIndex)));
            $optionLabel = trim((string) ($opt['label'] ?? ''));

            if ($selected !== '' && ($selected === $value || $selected === $optionLabel)) {
                $label = $optionLabel;
                break;
            }
        }

        if ($label !== '' && $custom !== '') {
            return $label . ' / ' . $custom;
        }

        if ($label !== '') {
            return $label;
        }

        if ($custom !== '') {
            return $custom;
        }

        return '—';
    }
}