<?php

use App\Services\Operations\OperationalContextBuilder;

it('renders a read-only operational preview without delivery', function () {
    $context = [
        'date' => '2026-07-31',
        'timezone' => 'Europe/Rome',
        'staff' => [
            'shift' => [
                'total' => 2,
                'working' => 1,
                'not_working' => 1,
                'label' => 'Средняя нагрузка',
            ],
            'working' => [['name' => 'Мария']],
            'not_working' => [['name' => 'Анна', 'reason' => 'Выходной']],
        ],
        'calendar' => ['events' => []],
        'requests' => [],
        'tasks' => [],
        'checks' => [],
        'tris_mare' => [],
        'mobility' => [],
        'telegram' => [
            'messages' => 0,
            'chats' => 0,
            'topics' => 0,
            'authors' => 0,
            'attachments' => 0,
            'signals' => [],
        ],
        'risks' => [],
        'data_quality' => [
            'telegram' => ['status' => 'empty'],
        ],
    ];

    $builder = Mockery::mock(OperationalContextBuilder::class);
    $builder->shouldReceive('build')
        ->once()
        ->withArgs(fn ($date) =>
            $date->toDateString() === '2026-07-31'
            && $date->getTimezone()->getName() === 'Europe/Rome'
        )
        ->andReturn($context);

    $this->app->instance(OperationalContextBuilder::class, $builder);

    $this->artisan('operational:preview', ['--date' => '2026-07-31'])
        ->expectsOutput('Режим: только чтение. AI не вызывается, Telegram не отправляется, данные не изменяются.')
        ->expectsOutputToContain('Работают: Мария')
        ->assertExitCode(0);
});
