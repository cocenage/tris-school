<x-filament-widgets::widget>
    <x-filament::section>
        @php
            $typeLabels = [
                'morning_digest' => 'Утренняя сводка', 'evening_intelligence' => 'Вечерняя сводка',
                'scheduled_control' => 'Контрольная рассылка', 'scheduled_control_summary' => 'Итог контролей',
                'mobility_alert' => 'Забастовка / Mobility', 'mobility_admin_alert' => 'Mobility для руководителей',
                'day_off' => 'Выходной', 'vacation' => 'Отпуск', 'inventory' => 'Инвентарь',
                'staff_form' => 'Форма сотрудника', 'user_access' => 'Доступ', 'task' => 'Задача',
                'assistant' => 'Assistant', 'instruction' => 'Инструкция', 'calendar' => 'Календарь',
                'apartment_handoff' => 'Передача квартиры', 'linen_defect' => 'Брак белья',
                'other' => 'Другая отправка',
            ];
            $statusLabels = [
                'sent' => 'принято API', 'edited' => 'отредактировано', 'pending' => 'ожидает',
                'expected' => 'ожидает отправки',
                'failed' => 'ошибка', 'missed' => 'пропущено', 'uncertain' => 'результат неизвестен',
                'retrying' => 'повторная попытка', 'unmonitored' => 'нет достоверного учёта',
            ];
            $historyStageLabels = [
                'build' => 'Формирование', 'calendar_source' => 'Источник календаря',
                'calendar_filter' => 'Фильтр событий календаря', 'calendar_format' => 'Текст календаря',
                'morning_alert_source' => 'Источник оповещений', 'morning_strike_source' => 'Источник забастовок',
                'morning_weather_source' => 'Источник погоды', 'morning_filter' => 'Фильтр транспорта',
                'morning_format' => 'Текст утренней сводки', 'controls_source' => 'Источник контролей',
                'controls_filter' => 'Фильтр контролей', 'controls_format' => 'Текст итогов контролей',
                'controls_queue' => 'Очередь отправки', 'evening_source' => 'Оперативные события',
                'evening_filter' => 'Фильтр оперативных событий', 'evening_linen_source' => 'Источник брака белья',
                'evening_format' => 'Текст вечерней сводки', 'evening_guard' => 'Защита от дубля',
                'linen_source' => 'Источник брака белья', 'linen_filter' => 'Фильтр случаев брака',
                'linen_format' => 'Текст отчёта о браке', 'linen_guard' => 'Защита от дубля',
                'send_attempt' => 'Попытка отправки', 'telegram_response' => 'Ответ Telegram',
            ];
            $historyOutcomeLabels = [
                'started' => 'начато', 'loaded' => 'источник загружен', 'complete' => 'завершено',
                'no_input' => 'нет входящих данных', 'no_significant_events' => 'значимых событий нет',
                'source_error' => 'ошибка источника', 'calculation_error' => 'ошибка расчёта',
                'failure_unclassified' => 'неуточнённый сбой', 'no_message' => 'сообщение не сформировано',
                'built' => 'сообщение сформировано', 'inline' => 'выполнено сразу', 'queued' => 'поставлено в очередь',
                'queue_failed' => 'ошибка постановки в очередь', 'skipped' => 'пропущено', 'attempted' => 'попытка выполнена',
                'accepted' => 'принято Telegram', 'rejected' => 'отказ Telegram', 'uncertain' => 'результат неизвестен',
            ];
            $historyMetricLabels = [
                'input_count' => 'Входных записей', 'filtered_count' => 'Отфильтровано',
                'included_count' => 'Включено', 'message_present' => 'Сообщение',
                'source_messages' => 'Входящих сообщений', 'observed_messages' => 'Обработанных сообщений',
                'events_considered' => 'Рассмотрено событий', 'events_included' => 'Включено событий',
                'events_omitted' => 'Исключено событий', 'controls_count' => 'Контролей',
                'responses_count' => 'Ответов', 'queued_count' => 'Заданий в очереди',
                'attempt' => 'Попытка', 'http_status' => 'HTTP статус', 'error_code' => 'Код ошибки',
            ];
        @endphp
        <x-slot name="heading">Telegram · Центр доставки</x-slot>
        <x-slot name="description">{{ $delivery['date'] }} · Europe/Rome · Telegram API подтверждает приём, но не прочтение сообщений</x-slot>

        @if (! $delivery['journal_available'])
            <p class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ $delivery['coverage_note'] }}</p>
        @endif
        @if ($delivery['source_error'])
            <p class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ $delivery['source_error'] }}. Счётчики неполные.</p>
        @endif

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach (['expected' => 'Ожидалось', 'sent' => 'Принято Telegram', 'pending' => 'Ожидает', 'failed' => 'Ошибки', 'missed' => 'Пропущено'] as $key => $label)
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-1 text-xl font-semibold">{{ $delivery['counts'][$key] }}</div>
                </div>
            @endforeach
        </div>

        @if ($delivery['counts']['uncertain'] || $delivery['counts']['edited'])
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                Неизвестный результат API: {{ $delivery['counts']['uncertain'] }} · Изменено сообщений: {{ $delivery['counts']['edited'] }}
            </p>
        @endif

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ $delivery['coverage_note'] }}</p>
        @if ($delivery['counts']['unmonitored'])
            <p class="mt-2 text-sm text-amber-800 dark:text-amber-300">Без достоверного учёта до начала журнала: {{ $delivery['counts']['unmonitored'] }}</p>
        @endif

        <details class="mt-4 text-sm">
            <summary class="cursor-pointer font-medium">Выпуски по расписанию</summary>
            <div class="mt-2 max-h-72 space-y-2 overflow-y-auto">
                @foreach ($delivery['slots'] as $slot)
                    <div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-800">
                        <span class="font-medium">{{ $typeLabels[$slot['type']] ?? $slot['type'] }}{{ $slot['district'] ? ' · '.$slot['district'] : '' }}{{ isset($slot['for_date']) ? ' · события '.$slot['for_date']->format('d.m.Y') : '' }}</span>
                        · {{ $slot['due']->format('H:i') }} · {{ $statusLabels[$slot['status']] ?? $slot['status'] }}
                        <div class="break-all text-xs text-gray-500">{{ $slot['bot'] }} · chat {{ $slot['chat_id'] ?? 'не настроен' }} · topic {{ $slot['thread_id'] ?? '—' }}{{ $slot['reason'] ? ' · '.$slot['reason'] : '' }}</div>
                    </div>
                @endforeach
            </div>
        </details>

        <details class="mt-4 text-sm">
            <summary class="cursor-pointer font-medium">Последние исходящие операции</summary>
            <div class="mt-2 max-h-72 space-y-2 overflow-y-auto">
                @forelse ($delivery['operations'] as $operation)
                    <div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-800">
                        <span class="font-medium">{{ $typeLabels[$operation->notification_type] ?? $operation->notification_type }}</span>
                        · {{ $operation->method }} · {{ $operation->status === 'pending' && $operation->attempted_at?->lt(now('Europe/Rome')->subMinutes(10)) ? 'результат неизвестен' : ($operation->status === 'expected' && $operation->created_at?->lt(now('Europe/Rome')->subMinutes(10)) ? 'пропущено до HTTP' : ($statusLabels[$operation->status] ?? $operation->status)) }}
                        · {{ $operation->created_at?->setTimezone('Europe/Rome')->format('H:i:s') }}
                        <div class="break-all text-xs text-gray-500">
                            {{ $operation->bot }} · chat {{ $operation->chat_id ?? '—' }} · topic {{ $operation->message_thread_id ?? '—' }}
                            · попыток {{ $operation->attempts }}
                            @if ($operation->source_type && $operation->source_id) · {{ $operation->source_type }} #{{ $operation->source_id }} @endif
                            @if ($operation->error_reason) · {{ $operation->error_reason }} @endif
                            @if ($operation->error_code) ({{ $operation->error_code }}) @endif
                        </div>
                        @if ($operation->relationLoaded('events') && $operation->events->isNotEmpty())
                            <div class="mt-2 border-t border-gray-200 pt-2 dark:border-gray-700">
                                <div class="mb-1 text-xs font-medium text-gray-600 dark:text-gray-300">История построения</div>
                                <div class="max-h-48 space-y-1 overflow-y-auto">
                                    @foreach ($operation->events as $event)
                                        @if (isset($historyStageLabels[$event->stage], $historyOutcomeLabels[$event->outcome]))
                                            <div class="text-xs text-gray-600 dark:text-gray-300">
                                                <span class="font-medium">{{ $event->created_at?->setTimezone('Europe/Rome')->format('H:i:s') }}</span>
                                                · {{ $historyStageLabels[$event->stage] }} · {{ $historyOutcomeLabels[$event->outcome] }}
                                                @php($metrics = is_array($event->metrics) ? $event->metrics : [])
                                                @foreach ($metrics as $metric => $value)
                                                    @if (isset($historyMetricLabels[$metric]) && (is_bool($value) || (is_int($value) && $value >= 0)))
                                                        · {{ $historyMetricLabels[$metric] }}: {{ is_bool($value) ? ($value ? 'да' : 'нет') : $value }}
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-gray-500">Пока нет записанных операций. Историческая доставка не подтверждена этим журналом.</p>
                @endforelse
            </div>
        </details>
    </x-filament::section>
</x-filament-widgets::widget>
