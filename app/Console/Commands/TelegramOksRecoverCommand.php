<?php

namespace App\Console\Commands;

use App\Jobs\RecoverOksDelivery;
use App\Models\TelegramScheduledMessageDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TelegramOksRecoverCommand extends Command
{
    protected $signature = 'telegram:oks-recover {delivery : Existing production delivery ID}
        {--confirmed-absent : Confirm that the message is absent from its Telegram topic}';

    protected $description = 'Recover a current-day uncertain production OKS delivery without creating a test delivery';

    public function handle(): int
    {
        if (! $this->option('confirmed-absent')) {
            $this->error('Проверьте рабочую тему Telegram и укажите --confirmed-absent, только если сообщения действительно нет.');

            return self::INVALID;
        }

        $now = CarbonImmutable::now('Europe/Rome');
        $delivery = TelegramScheduledMessageDelivery::query()->find($this->argument('delivery'));
        if ($delivery === null || $delivery->status !== 'uncertain'
            || $delivery->telegram_message_id !== null || $delivery->sent_at !== null
            || ! RecoverOksDelivery::isCurrentDestination($delivery, $now)) {
            $this->error('Доставка не подходит для восстановления.');

            return self::FAILURE;
        }

        if ($delivery->updated_at === null || $delivery->updated_at->gt($now->subMinutes(2))) {
            $this->error('Дождитесь завершения возможной текущей попытки (не менее двух минут).');

            return self::FAILURE;
        }

        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            $this->error('Требуется асинхронная очередь.');

            return self::FAILURE;
        }

        RecoverOksDelivery::dispatch($delivery->id, $now->addHour(), (string) $delivery->getRawOriginal('updated_at'));
        Log::warning('Operator confirmed uncertain OKS message absent.', ['delivery_id' => $delivery->id]);
        $this->info('Запрос восстановления поставлен в очередь. Проверьте статус доставки после обработки.');

        return self::SUCCESS;
    }
}
