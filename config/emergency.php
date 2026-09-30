<?php

return [
    'build_path' => env('EMERGENCY_BUILD_PATH', storage_path('app/emergency')),
    'publication_disk' => env('EMERGENCY_FILESYSTEM_DISK'),
    'publication_prefix' => env('EMERGENCY_FILESYSTEM_PREFIX', ''),
    'feedback_endpoint' => env('EMERGENCY_FEEDBACK_ENDPOINT'),
    'stale_warning_hours' => (int) env('EMERGENCY_STALE_WARNING_HOURS', 12),
    'stale_critical_hours' => (int) env('EMERGENCY_STALE_CRITICAL_HOURS', 48),
    'feedback' => [
        'types' => [
            ['value' => 'problem', 'label' => 'Проблема'],
            ['value' => 'question', 'label' => 'Вопрос'],
            ['value' => 'change', 'label' => 'Изменение'],
            ['value' => 'completed', 'label' => 'Выполнено'],
            ['value' => 'help', 'label' => 'Нужна помощь'],
            ['value' => 'other', 'label' => 'Другое'],
        ],
        'areas' => [
            ['value' => 'cleaning', 'label' => 'Уборка'],
            ['value' => 'access', 'label' => 'Доступ'],
            ['value' => 'courier', 'label' => 'Курьер'],
            ['value' => 'payment', 'label' => 'Оплата'],
            ['value' => 'apartment', 'label' => 'Квартира'],
            ['value' => 'system', 'label' => 'Система'],
            ['value' => 'other', 'label' => 'Другое'],
        ],
        'urgencies' => [
            ['value' => 'normal', 'label' => 'Обычная'],
            ['value' => 'important', 'label' => 'Важная'],
            ['value' => 'urgent', 'label' => 'Срочная'],
        ],
    ],
];
