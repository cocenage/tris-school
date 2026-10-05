<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class QueueConnectionProbeJob implements ShouldQueue
{
    public function handle(): void {}
}

afterEach(function (): void {
    DB::purge('queue_test');
    DB::purge('sqlite');
});

it('stores queued and failed jobs on the configured queue database without changing the application database', function () {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
        'database.connections.queue_test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'queue.default' => 'database',
        'queue.connections.database.connection' => 'queue_test',
        'queue.batching.database' => 'queue_test',
        'queue.failed.database' => 'queue_test',
    ]);

    DB::purge('sqlite');
    DB::purge('queue_test');

    Schema::connection('sqlite')->create('application_probe', function (Blueprint $table): void {
        $table->id();
        $table->string('value');
    });
    Schema::connection('queue_test')->create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    Schema::connection('queue_test')->create('failed_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });

    DB::connection('sqlite')->table('application_probe')->insert(['value' => 'primary remains available']);
    Queue::connection('database')->push(new QueueConnectionProbeJob);
    app(FailedJobProviderInterface::class)->log(
        'database',
        'default',
        json_encode(['uuid' => '00000000-0000-0000-0000-000000000001'], JSON_THROW_ON_ERROR),
        new RuntimeException('fixture failure'),
    );

    expect(DB::connection('queue_test')->table('jobs')->count())->toBe(1)
        ->and(DB::connection('queue_test')->table('failed_jobs')->count())->toBe(1)
        ->and(Schema::connection('sqlite')->hasTable('jobs'))->toBeFalse()
        ->and(DB::connection('sqlite')->table('application_probe')->value('value'))->toBe('primary remains available');
});
