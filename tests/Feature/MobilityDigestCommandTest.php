<?php

use App\Models\MobilityAlert;
use App\Console\Commands\MobilityDigestCommand;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Http::fake(['*' => Http::response(['hourly' => []])]);
    Schema::dropIfExists('mobility_alerts');
    Schema::create('mobility_alerts', function (Blueprint $table): void {
        $table->id();
        $table->string('source')->nullable();
        $table->string('title');
        $table->text('description')->nullable();
        $table->string('url')->nullable();
        $table->string('type')->nullable();
        $table->string('risk')->default('medium');
        $table->string('district')->nullable();
        $table->date('starts_at')->nullable();
        $table->date('ends_at')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->string('external_hash')->unique();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('mobility_alerts');
});

it('deduplicates mobility events and never treats a regular status as a high alert', function () {
    config([
        'services.telegram.mobility_targets' => null,
        'services.telegram.analytics_bot_token' => null,
    ]);

    MobilityAlert::create([
        'source' => 'atm',
        'title' => 'M2 REGOLARE',
        'type' => 'info',
        'risk' => 'high',
        'district' => 'M2',
        'starts_at' => '2026-08-03',
        'external_hash' => 'regular',
    ]);
    MobilityAlert::create([
        'source' => 'atm',
        'title' => 'M2 chiusura tra Gobba e Cologno Nord',
        'description' => 'Autobus sostitutivi da Gobba https://tracker.invalid/x',
        'type' => 'partial_closure',
        'risk' => 'medium',
        'district' => 'M2',
        'starts_at' => '2026-08-03',
        'external_hash' => 'closure-a',
    ]);
    MobilityAlert::create([
        'source' => 'milanmetrost',
        'title' => 'M2 chiusura tra Gobba e Cologno Nord!',
        'description' => 'Autobus sostitutivi da Gobba',
        'type' => 'partial_closure',
        'risk' => 'medium',
        'district' => 'M2',
        'starts_at' => '2026-08-03',
        'external_hash' => 'closure-b',
    ]);

    $command = app(MobilityDigestCommand::class);
    $method = new ReflectionMethod($command, 'buildMessage');
    $method->setAccessible(true);
    $message = $method->invoke(
        $command,
        Carbon::parse('2026-08-03'),
        MobilityAlert::query()->get()
    );

    expect($message)
        ->toContain('Передвижение')
        ->toContain('gobba')
        ->toContain('autobus')
        ->not->toContain('HIGH')
        ->not->toContain('REGOLARE');

    $this->artisan('mobility:digest', ['--date' => '2026-08-03', '--dry-run' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Передвижение');
});
