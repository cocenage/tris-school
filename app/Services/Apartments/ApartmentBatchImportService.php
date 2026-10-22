<?php

namespace App\Services\Apartments;

use App\Models\Apartment;
use App\Models\ApartmentTelegramImport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApartmentBatchImportService
{
    public function __construct(private TelegramApartmentImportService $importer) {}

    /** Preview never creates apartments, sections, files or ledger entries. */
    public function preview(array $entries): array
    {
        $counts = array_fill_keys(['new', 'update', 'matched', 'ambiguous', 'error'], 0);
        $rows = [];
        $targets = [];
        $sources = [];
        $apartments = Apartment::query()->get();

        foreach ($entries as $index => $entry) {
            $row = ['row' => $index + 1, 'status' => 'error', 'apartment_id' => null];
            try {
                if (! is_array($entry) || ! is_string($entry['json'] ?? null) || ! is_file($entry['json'])) {
                    throw new \InvalidArgumentException('Укажите существующий JSON-файл.');
                }
                if (filesize($entry['json']) > 50 * 1024 * 1024) {
                    throw new \InvalidArgumentException('JSON превышает 50 МБ.');
                }
                $export = json_decode(file_get_contents($entry['json']), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($export) || ! is_array($export['messages'] ?? null) || isset($export['chats'])) {
                    throw new \InvalidArgumentException('Нужен отдельный экспорт одного чата с messages. Общий экспорт сначала разделите и сопоставьте.');
                }
                $preview = $this->importer->preview($entry['json'], $entry['media'] ?? null);
                if ($preview['message_count'] === 0 || $preview['media_missing_count'] > 0) {
                    throw new \InvalidArgumentException('Нет содержательных сообщений или отсутствуют/не поддерживаются медиафайлы.');
                }
                $address = $this->normalise((string) ($entry['address'] ?? ''));
                if (isset($entry['apartment_id'])) {
                    $matches = $apartments->where('id', $entry['apartment_id']);
                    if ($matches->count() !== 1) {
                        throw new \InvalidArgumentException('Указанная квартира не найдена.');
                    }
                    if ($address !== '' && $this->normalise((string) $matches->first()->address) !== $address) {
                        throw new \InvalidArgumentException('ID квартиры противоречит указанному адресу.');
                    }
                } else {
                    if ($address === '' || trim((string) ($entry['name'] ?? '')) === '') {
                        throw new \InvalidArgumentException('Нужен apartment_id или подтверждённые name и address.');
                    }
                    $matches = $apartments->filter(fn (Apartment $apartment) => $this->normalise((string) $apartment->address) === $address);
                }
                if ($matches->count() > 1) {
                    $row['status'] = 'ambiguous';
                    $row['reason'] = 'Несколько квартир с этим адресом: укажите apartment_id.';
                } else {
                    $apartment = $matches->first();
                    $row['apartment_id'] = $apartment?->id;
                    $target = $apartment ? 'id:'.$apartment->id : 'address:'.$address;
                    $hash = $preview['sha256'];
                    if (isset($targets[$target]) || isset($sources[$hash])) {
                        throw new \InvalidArgumentException('Повтор квартиры или экспорта внутри пакета. Объедините/уточните список.');
                    }
                    $targets[$target] = true;
                    $sources[$hash] = true;
                    $imports = ApartmentTelegramImport::query()->where('sha256', $hash)->get();
                    if ($imports->contains(fn ($import) => $import->apartment_id !== $apartment?->id)) {
                        throw new \InvalidArgumentException('Этот экспорт уже связан с другой квартирой.');
                    }
                    $row['status'] = $imports->isNotEmpty() ? 'matched' : ($apartment ? 'update' : 'new');
                    if ($row['status'] === 'update' && ApartmentTelegramImport::where('apartment_id', $apartment->id)->exists()) {
                        throw new \InvalidArgumentException('У квартиры уже есть другой экспорт. Требуется сверка перекрывающихся сообщений.');
                    }
                    $row['messages'] = $preview['message_count'];
                    $row['media'] = $preview['media_available_count'];
                }
            } catch (\InvalidArgumentException|\JsonException|ValidationException $exception) {
                $row['status'] = 'error';
                $row['reason'] = $exception->getMessage();
            }
            $counts[$row['status']]++;
            $rows[] = $row;
        }

        return ['counts' => $counts, 'rows' => $rows];
    }

    public function import(array $entries, User $actor): array
    {
        if (! app(ApartmentAccessService::class)->canManage($actor)) {
            throw new AuthorizationException('Импорт разрешён только управляющему квартирами.');
        }

        // Shared lock prevents two batch commands from creating the same new apartment.
        return Cache::lock('apartments:telegram-batch-import', 86400)->get(function () use ($entries, $actor) {
            $report = $this->preview($entries);
            if ($report['counts']['error'] + $report['counts']['ambiguous'] > 0) {
                throw ValidationException::withMessages(['manifest' => 'Импорт остановлен: устраните ошибки предпросмотра.']);
            }
            foreach ($report['rows'] as $index => &$row) {
                if ($row['status'] === 'matched') {
                    continue;
                }
                $entry = $entries[$index];
                // Each apartment commits independently; a restart skips completed exports.
                DB::transaction(function () use ($entry, $actor, &$row) {
                    $apartment = $row['apartment_id'] ? Apartment::findOrFail($row['apartment_id']) : Apartment::create([
                        'name' => trim($entry['name']), 'address' => trim($entry['address']),
                        'information_status' => 'draft', 'is_active' => false,
                    ]);
                    $this->importer->import($apartment, $entry['json'], $entry['media'] ?? null, $actor);
                    $row['apartment_id'] = $apartment->id;
                    $row['result'] = 'imported';
                });
            }

            return $report;
        }) ?: throw ValidationException::withMessages(['manifest' => 'Другой пакетный импорт уже запущен.']);
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }
}
