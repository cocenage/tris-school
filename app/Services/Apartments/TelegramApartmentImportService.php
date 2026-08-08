<?php

namespace App\Services\Apartments;

use App\Models\Apartment;
use App\Models\ApartmentInformationAttachment;
use App\Models\ApartmentInformationSection;
use App\Models\ApartmentTelegramImport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class TelegramApartmentImportService
{
    private const MAX_JSON_BYTES = 50 * 1024 * 1024;

    /** @return array<string, mixed> */
    public function preview(string $jsonPath, ?string $mediaPath = null): array
    {
        $export = $this->readExport($jsonPath);
        $items = $this->extractMessages($export);
        $rows = [];
        $skipped = 0;
        $photos = 0;
        $documents = 0;

        foreach ($items as $item) {
            $row = $this->normaliseMessage($item);
            if ($row === null) {
                $skipped++;
                continue;
            }
            $rows[] = $row;
            $photos += $row['photo'] ? 1 : 0;
            $documents += $row['document'] ? 1 : 0;
        }

        $stat = stat($jsonPath);
        $hash = hash_file('sha256', $jsonPath);
        $duplicate = ApartmentTelegramImport::query()->where('sha256', $hash)->first();

        return [
            'original_name' => basename($jsonPath),
            'file_size' => (int) ($stat['size'] ?? 0),
            'sha256' => $hash,
            'message_count' => count($rows),
            'photo_count' => $photos,
            'document_count' => $documents,
            'skipped_count' => $skipped,
            'date_from' => $rows[0]['date'] ?? null,
            'date_to' => $rows !== [] ? $rows[array_key_last($rows)]['date'] : null,
            'samples' => array_map(fn (array $row): string => $this->sample($row), array_slice($rows, 0, 5)),
            'media_path' => $mediaPath,
            'already_imported' => $duplicate !== null,
            'duplicate_import_id' => $duplicate?->getKey(),
            '_rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    public function import(Apartment $apartment, string $jsonPath, ?string $mediaPath, User $actor): array
    {
        if (! app(ApartmentAccessService::class)->canManage($actor)) {
            throw new AuthorizationException('Импорт доступен только администратору или глобальному менеджеру.');
        }

        $preview = $this->preview($jsonPath, $mediaPath);
        $duplicate = ApartmentTelegramImport::query()
            ->where('apartment_id', $apartment->getKey())
            ->where('sha256', $preview['sha256'])
            ->first();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'json_path' => 'Этот экспорт уже импортирован в систему для выбранной квартиры.',
            ]);
        }

        return DB::transaction(function () use ($apartment, $actor, $preview, $mediaPath): array {
            $rows = $preview['_rows'];
            $section = ApartmentInformationSection::query()->create([
                'apartment_id' => $apartment->getKey(),
                'type' => 'custom',
                'title' => 'Telegram import — '.$preview['original_name'],
                'content' => collect($rows)->map(fn (array $row): string => $this->sample($row))->implode("\n"),
                'sort_order' => ((int) $apartment->informationSections()->max('sort_order')) + 1,
                'is_visible' => false,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $media = $this->mediaEntries($mediaPath);
            $storedPhotos = 0;
            $storedDocuments = 0;
            $skipped = (int) $preview['skipped_count'];
            $order = 0;

            foreach ($rows as $row) {
                $entry = $this->findMedia($media, $row['media']);
                if ($entry === null) {
                    if ($row['media'] !== null) {
                        $skipped++;
                    }
                    continue;
                }

                $extension = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
                $relative = 'apartment-information/'.$apartment->getKey().'/telegram-import-'.$preview['sha256'].'/'.$order.'.'.$extension;
                Storage::disk('local')->put($relative, $entry['contents']);
                ApartmentInformationAttachment::query()->create([
                    'apartment_id' => $apartment->getKey(),
                    'information_section_id' => $section->getKey(),
                    'disk' => 'local',
                    'path' => $relative,
                    'original_name' => basename($entry['name']),
                    'mime_type' => $entry['mime'],
                    'file_size' => strlen($entry['contents']),
                    'caption' => $row['text'],
                    'sort_order' => $order++,
                    'uploaded_by' => $actor->getKey(),
                ]);
                $entry['mime'] === 'application/pdf' ? $storedDocuments++ : $storedPhotos++;
            }

            ApartmentTelegramImport::query()->create([
                'apartment_id' => $apartment->getKey(),
                'original_name' => $preview['original_name'],
                'file_size' => $preview['file_size'],
                'sha256' => $preview['sha256'],
                'status' => 'draft',
                'message_count' => $preview['message_count'],
                'photo_count' => $storedPhotos,
                'document_count' => $storedDocuments,
                'skipped_count' => $skipped,
                'imported_by' => $actor->getKey(),
                'imported_at' => now(),
            ]);

            return [
                'section_id' => $section->getKey(),
                'message_count' => $preview['message_count'],
                'photo_count' => $storedPhotos,
                'document_count' => $storedDocuments,
                'skipped_count' => $skipped,
            ];
        });
    }

    /** @return array<string, mixed> */
    private function readExport(string $path): array
    {
        if (! is_file($path) || filesize($path) > self::MAX_JSON_BYTES) {
            throw ValidationException::withMessages(['json_path' => 'JSON-файл не найден или слишком большой.']);
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['json_path' => 'Файл не является корректным Telegram JSON экспортом.']);
        }
        if (! is_array($data)) {
            throw ValidationException::withMessages(['json_path' => 'Ожидался JSON-объект Telegram Desktop.']);
        }
        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function extractMessages(array $export): array
    {
        if (isset($export['messages']) && is_array($export['messages'])) {
            return array_values(array_filter($export['messages'], 'is_array'));
        }
        $messages = [];
        foreach (($export['chats']['list'] ?? []) as $chat) {
            foreach (($chat['messages'] ?? []) as $message) {
                if (is_array($message)) {
                    $messages[] = $message;
                }
            }
        }
        return $messages;
    }

    /** @return array<string, mixed>|null */
    private function normaliseMessage(array $item): ?array
    {
        if (($item['type'] ?? 'message') !== 'message') {
            return null;
        }
        $text = trim($this->textValue($item['text'] ?? $item['caption'] ?? null));
        $media = $item['photo'] ?? $item['file'] ?? $item['document'] ?? null;
        if ($text === '' && ! is_string($media)) {
            return null;
        }
        if (str_starts_with($text, '/')) {
            return null;
        }
        $date = (string) ($item['date'] ?? $item['date_unixtime'] ?? '');
        return [
            'date' => $date,
            'author' => trim((string) ($item['from'] ?? $item['from_name'] ?? '')),
            'text' => $text,
            'media' => is_string($media) ? $media : null,
            'photo' => is_string($item['photo'] ?? null),
            'document' => is_string($item['file'] ?? $item['document'] ?? null),
        ];
    }

    private function textValue(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return collect($value)->map(fn ($part): string => is_array($part) ? $this->textValue($part['text'] ?? '') : $this->textValue($part))->implode('');
        }
        return '';
    }

    private function sample(array $row): string
    {
        $prefix = $row['date'] !== '' ? '['.$row['date'].'] ' : '';
        $author = $row['author'] !== '' ? $row['author'].': ' : '';
        return trim($prefix.$author.($row['text'] !== '' ? $row['text'] : '[media]'));
    }

    /** @return list<array{name:string,mime:string,contents:string}> */
    private function mediaEntries(?string $path): array
    {
        if (! $path || ! is_file($path)) {
            return [];
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['media_path' => 'Не удалось открыть архив media.']);
        }
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || str_contains(str_replace('\\', '/', $name), '../') || str_starts_with($name, '/')) {
                continue;
            }
            $contents = $zip->getFromIndex($i);
            if ($contents === false) {
                continue;
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream';
            if (! in_array($mime, ApartmentInformationAttachment::ALLOWED_MIME_TYPES, true)) {
                continue;
            }
            $entries[] = ['name' => $name, 'mime' => $mime, 'contents' => $contents];
        }
        $zip->close();
        return $entries;
    }

    private function findMedia(array $entries, ?string $name): ?array
    {
        if ($name === null) {
            return null;
        }
        $needle = str_replace('\\', '/', $name);
        foreach ($entries as $entry) {
            if ($entry['name'] === $needle || basename($entry['name']) === basename($needle)) {
                return $entry;
            }
        }
        return null;
    }
}
