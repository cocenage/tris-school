<?php

use App\Services\Operations\OperationalContextBuilder;

it('renders the deterministic morning preview without delivery', function () {
    $context = [
        'date' => '2026-07-31',
        'timezone' => 'Europe/Rome',
        'staff' => [
            'shift' => ['total' => 2],
            'working' => [['name' => 'Мария']],
            'not_working' => [['name' => 'Анна', 'reason' => 'Выходной']],
        ],
        'calendar' => ['events' => []],
        'requests' => ['items' => []],
        'tasks' => ['total' => 0, 'open' => 0, 'items' => []],
        'checks' => [],
        'tris_mare' => [],
        'mobility' => ['items' => []],
        'telegram' => ['messages' => 0],
        'risks' => [],
        'data_quality' => [],
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
        ->assertExitCode(0);
});
