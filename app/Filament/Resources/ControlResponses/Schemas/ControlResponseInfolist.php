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
                Section::make('')
                    ->schema([
                        TextEntry::make('summary_rendered')
                            ->label('')
                            ->state(fn (ControlResponse $record) => self::renderSummary($record))
                            ->html()
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

    protected static function renderSummary(ControlResponse $record): string
    {
        $zoneColor = match ($record->result_zone) {
            'green' => ['bg' => '#f0fdf4', 'border' => '#bbf7d0', 'text' => '#166534'],
            'yellow' => ['bg' => '#fffbeb', 'border' => '#fde68a', 'text' => '#92400e'],
            'red' => ['bg' => '#fff1f2', 'border' => '#fecaca', 'text' => '#991b1b'],
            default => ['bg' => '#f9fafb', 'border' => '#e5e7eb', 'text' => '#374151'],
        };

        $name = e($record->cleaner?->name ?? '—');
        $apartment = e($record->apartment?->name ?? '—');
        $supervisor = e($record->supervisor?->name ?? '—');
        $control = e($record->control?->name ?? '—');
        $date = e($record->inspection_date?->format('d.m.Y') ?? '—');
        $zone = e($record->result_zone_label ?? 'Без оценки');
        $reason = e($record->result_zone_reason ?? '—');
        $errors = (int) ($record->errors_count ?? 0);
        $penalty = (int) ($record->penalty_points ?? 0);
        $comment = trim((string) ($record->comment ?? ''));

        $html = '
            <div style="
                border:1px solid ' . $zoneColor['border'] . ';
                background:' . $zoneColor['bg'] . ';
                border-radius:24px;
                padding:18px;
                display:flex;
                flex-direction:column;
                gap:16px;
            ">
                <div style="
                    display:flex;
                    justify-content:space-between;
                    align-items:flex-start;
                    gap:16px;
                    flex-wrap:wrap;
                ">
                    <div>
                        <div style="
                            color:#6b7280;
                            font-size:13px;
                            font-weight:700;
                            margin-bottom:4px;
                        ">
                            Контроль
                        </div>

                        <div style="
                            color:#111827;
                            font-size:24px;
                            font-weight:900;
                            line-height:1.15;
                        ">
                            ' . $name . '
                        </div>

                        <div style="
                            color:#6b7280;
                            font-size:14px;
                            margin-top:6px;
                        ">
                            ' . $apartment . ' · ' . $date . '
                        </div>
                    </div>

                    <div style="
                        background:' . $zoneColor['text'] . ';
                        color:white;
                        border-radius:999px;
                        padding:8px 12px;
                        font-size:13px;
                        font-weight:900;
                        white-space:nowrap;
                    ">
                        ' . $zone . '
                    </div>
                </div>

                <div style="
                    display:grid;
                    grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
                    gap:10px;
                ">
                    ' . self::summaryItem('Ошибок', (string) $errors) . '
                    ' . self::summaryItem('Штраф', (string) $penalty) . '
                    ' . self::summaryItem('Проверил', $supervisor) . '
                    ' . self::summaryItem('Форма', $control) . '
                </div>

                <div style="
                    border-top:1px solid ' . $zoneColor['border'] . ';
                    padding-top:14px;
                ">
                    <div style="
                        color:' . $zoneColor['text'] . ';
                        font-size:13px;
                        font-weight:800;
                        margin-bottom:4px;
                    ">
                        Причина зоны
                    </div>

                    <div style="
                        color:#111827;
                        font-size:15px;
                        font-weight:700;
                        line-height:1.4;
                    ">
                        ' . $reason . '
                    </div>
                </div>
        ';

        if ($comment !== '') {
            $html .= '
                <div style="
                    border-top:1px solid ' . $zoneColor['border'] . ';
                    padding-top:14px;
                ">
                    <div style="
                        color:#6b7280;
                        font-size:13px;
                        font-weight:800;
                        margin-bottom:4px;
                    ">
                        Комментарий
                    </div>

                    <div style="
                        color:#111827;
                        font-size:15px;
                        line-height:1.45;
                    ">
                        ' . nl2br(e($comment)) . '
                    </div>
                </div>
            ';
        }

        $html .= '</div>';

        return $html;
    }

    protected static function summaryItem(string $label, string $value): string
    {
        return '
            <div style="
                background:rgba(255,255,255,0.75);
                border:1px solid rgba(229,231,235,0.8);
                border-radius:16px;
                padding:12px;
            ">
                <div style="
                    color:#6b7280;
                    font-size:12px;
                    font-weight:800;
                    margin-bottom:4px;
                ">
                    ' . e($label) . '
                </div>

                <div style="
                    color:#111827;
                    font-size:15px;
                    font-weight:800;
                    line-height:1.3;
                ">
                    ' . e($value) . '
                </div>
            </div>
        ';
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