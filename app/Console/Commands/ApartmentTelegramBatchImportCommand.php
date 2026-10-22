<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Apartments\ApartmentBatchImportService;
use Illuminate\Console\Command;

class ApartmentTelegramBatchImportCommand extends Command
{
    protected $signature = 'apartments:telegram-import {manifest : Local JSON mapping file} {--dry-run : Read-only preview (default)} {--apply : Import the reviewed mapping} {--actor= : Existing authorized user ID}';

    protected $description = 'Preview or import apartment Telegram exports locally without overwriting existing fields';

    public function handle(ApartmentBatchImportService $service): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Команда доступна только в local/testing.');

            return self::FAILURE;
        }
        try {
            $path = realpath($this->argument('manifest'));
            if (! $path || ! is_file($path) || filesize($path) > 1024 * 1024) {
                throw new \RuntimeException('Список импорта отсутствует или превышает 1 МБ.');
            }
            $entries = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($entries) || ! array_is_list($entries) || $entries === []) {
                throw new \RuntimeException('Ожидался непустой JSON-массив связей.');
            }
            foreach ($entries as &$entry) {
                foreach (['json', 'media'] as $field) {
                    if (isset($entry[$field]) && is_string($entry[$field])) {
                        $relative = realpath(dirname($path).DIRECTORY_SEPARATOR.$entry[$field]);
                        $entry[$field] = $relative ?: $entry[$field];
                    }
                }
            }
            unset($entry);
            $report = $service->preview($entries);
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($report['counts']['error'] + $report['counts']['ambiguous'] > 0) {
                return self::FAILURE;
            }
            if ($this->option('apply')) {
                if ($this->option('dry-run') || ! $this->option('actor')) {
                    throw new \RuntimeException('Для --apply нужен --actor; --dry-run несовместим с --apply.');
                }
                $result = $service->import($entries, User::findOrFail($this->option('actor')));
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            // SQL/driver exceptions can include personal data. Keep console diagnostics bounded.
            $this->error('Импорт не завершён: '.class_basename($exception).'. Проверьте входной список и повторите dry-run.');

            return self::FAILURE;
        }
    }
}
