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
                    ->columns(3)
                    ->schema([
                        TextEntry::make('cleaner.name')
                            ->label('Имя'),

                        TextEntry::make('result_zone_label')
                            ->label('Цвет')
                            ->badge()
                            ->color(fn (ControlResponse $record): string => $record->result_zone_color),

                        TextEntry::make('inspection_date')
                            ->label('Дата контроля')
                            ->date('d.m.Y'),

                        TextEntry::make('apartment.name')
                            ->label('Квартира'),

                        TextEntry::make('supervisor.name')
                            ->label('Кто проверил'),

                        TextEntry::make('control.name')
                            ->label('Форма'),

                        TextEntry::make('comment')
                            ->label('Комментарий')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Ошибки')
                    ->schema([
                        TextEntry::make('errors_rendered')
                            ->label('')
                            ->state(fn (ControlResponse $record) => self::renderErrors($record))
                            ->html()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected static function renderErrors(ControlResponse $record): string
    {
        $schema = is_array($record->schema_snapshot) ? $record->schema_snapshot : [];
        $responses = is_array($record->responses) ? $record->responses : [];

        if (empty($schema)) {
            return '<div style="color:#777;">Нет данных</div>';
        }

        $analysis = ControlResponse::analyzeAnswers($schema, $responses);
        $errors = $analysis['errors'] ?? [];

        if (empty($errors)) {
            return '
                <div style="
                    border:1px solid #bbf7d0;
                    background:#f0fdf4;
                    color:#166534;
                    border-radius:18px;
                    padding:16px;
                    font-weight:700;
                ">
                    Ошибок нет. Контроль в зелёной зоне.
                </div>
            ';
        }

        $html = '<div style="display:flex;flex-direction:column;gap:14px;">';

        $html .= '
            <div style="
                border:1px solid #fecaca;
                background:#fff1f2;
                color:#991b1b;
                border-radius:18px;
                padding:14px 16px;
                font-weight:700;
            ">
                Найдено ошибок: ' . count($errors) . ' · Штрафных баллов: ' . (int) $analysis['penalty_points'] . '
            </div>
        ';

        foreach ($errors as $error) {
            $roomTitle = e($error['room_title'] ?? 'Комната');
            $question = e($error['question'] ?? 'Вопрос');
            $answer = e($error['selected_label'] ?? '—');
            $penalty = (int) ($error['penalty_points'] ?? 0);
            $max = (int) ($error['max_points'] ?? 0);
            $isCritical = (bool) ($error['is_critical'] ?? false);
            $media = is_array($error['media'] ?? null) ? $error['media'] : [];

            $html .= '
                <div style="
                    border:1px solid #fecaca;
                    background:#fff;
                    border-radius:20px;
                    overflow:hidden;
                    box-shadow:0 10px 30px rgba(153,27,27,0.06);
                ">
                    <div style="
                        background:#fee2e2;
                        color:#991b1b;
                        padding:12px 16px;
                        font-weight:800;
                        display:flex;
                        justify-content:space-between;
                        gap:12px;
                    ">
                        <span>' . $roomTitle . '</span>
                        <span>−' . $penalty . ' / ' . $max . '</span>
                    </div>

                    <div style="padding:14px 16px;">
                        ' . ($isCritical ? '
                            <div style="
                                display:inline-flex;
                                margin-bottom:10px;
                                border-radius:999px;
                                background:#dc2626;
                                color:white;
                                padding:5px 10px;
                                font-size:12px;
                                font-weight:800;
                            ">
                                Критическая ошибка
                            </div>
                        ' : '') . '

                        <div style="
                            color:#6b7280;
                            font-size:13px;
                            margin-bottom:6px;
                            line-height:1.35;
                        ">
                            ' . $question . '
                        </div>

                        <div style="
                            color:#111827;
                            font-size:15px;
                            font-weight:800;
                            line-height:1.4;
                        ">
                            ' . $answer . '
                        </div>
                    ';

            if (! empty($media)) {
                $html .= '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;">';

                foreach ($media as $photo) {
                    $url = e((string) ($photo['url'] ?? ''));

                    if ($url === '') {
                        continue;
                    }

                    $html .= '
                        <a href="' . $url . '" target="_blank" style="display:block;">
                            <img
                                src="' . $url . '"
                                alt=""
                                style="
                                    width:120px;
                                    height:120px;
                                    object-fit:cover;
                                    border-radius:16px;
                                    border:1px solid #fecaca;
                                "
                            >
                        </a>
                    ';
                }

                $html .= '</div>';
            }

            $html .= '
                    </div>
                </div>
            ';
        }

        $html .= '</div>';

        return $html;
    }
}