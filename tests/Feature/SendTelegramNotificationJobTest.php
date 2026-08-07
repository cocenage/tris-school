<?php

use App\Jobs\SendTelegramNotificationJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class RetryableTelegramServiceStub
{
    public static int $calls = 0;

    public function send(object $record): void
    {
        self::$calls++;

        if (self::$calls === 1) {
            throw new RuntimeException('temporary Telegram outage');
        }
    }
}

beforeEach(function () {
    RetryableTelegramServiceStub::$calls = 0;

    Schema::dropIfExists('retry_job_records');
    Schema::create('retry_job_records', function (Blueprint $table): void {
        $table->id();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('retry_job_records');
});

it('retries a failed Telegram delivery with configured backoff', function () {
    $record = new class extends \Illuminate\Database\Eloquent\Model {
        protected $table = 'retry_job_records';
        public $timestamps = true;
        protected $guarded = [];
    };
    $record->save();

    $job = new SendTelegramNotificationJob(
        RetryableTelegramServiceStub::class,
        'send',
        get_class($record),
        $record->id,
    );

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 30]);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    $job->handle();

    expect(RetryableTelegramServiceStub::$calls)->toBe(2);
});
