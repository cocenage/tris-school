<?php

namespace App\Services\Apartments;

use App\Models\Apartment;
use App\Models\ApartmentInformationAttachment;
use App\Models\ApartmentInformationSection;
use App\Models\ApartmentTelegramImport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use ZipArchive;

class TelegramApartmentImportService
{
    private const MAX_JSON_BYTES = 50 * 1024 * 1024;

    /** @return array<string, mixed> */
    public function preview(mixed $jsonUpload, mixed $mediaUpload = null): array
    {
        $json = $this->resolveUpload($jsonUpload, 'json_path');
        $export = $this->readExport($json);
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

        $rows = collect($rows)
            ->sortBy(fn (array $row): int => $this->dateSortValue($row['date']))
            ->values()
            ->all();

        $mediaEntries = $this->mediaEntries($mediaUpload);
        $mediaReferences = collect($rows)->filter(fn (array $row): bool => $row['media_reference'] !== null);
        $availableMedia = $mediaReferences->filter(
            fn (array $row): bool => $this->findMedia($mediaEntries, $row['media']) !== null,
        );
        $notIncludedMedia = $mediaReferences->filter(fn (array $row): bool => $row['media_placeholder']);
        $missingMedia = $mediaReferences->count() - $availableMedia->count();
        $hash = hash_file('sha256', $json['absolute_path']);
        $duplicate = ApartmentTelegramImport::query()->where('sha256', $hash)->first();

        return [
            'original_name' => $json['original_name'],
            'file_size' => $json['size'],
            'sha256' => $hash,
            'message_count' => count($rows),
            'text_message_count' => collect($rows)->filter(fn (array $row): bool => $row['text'] !== '')->count(),
            'photo_count' => $photos,
            'document_count' => $documents,
            'skipped_count' => $skipped,
            'media_reference_count' => $mediaReferences->count(),
            'media_available_count' => $availableMedia->count(),
            'media_missing_count' => $missingMedia,
            'media_not_included_count' => $notIncludedMedia->count(),
            'chat_name' => (string) ($export['name'] ?? ''),
            'chat_type' => (string) ($export['type'] ?? ''),
            'chat_id' => $export['id'] ?? null,
            'date_from' => $rows[0]['date'] ?? null,
            'date_to' => $rows !== [] ? $rows[array_key_last($rows)]['date'] : null,
            'samples' => array_map(fn (array $row): string => $this->sample($row), array_slice($rows, 0, 5)),
            'media_path' => $mediaUpload,
            'already_imported' => $duplicate !== null,
            'duplicate_import_id' => $duplicate?->getKey(),
            '_rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    public function import(Apartment $apartment, mixed $jsonUpload, mixed $mediaUpload, User $actor): array
    {
        if (! app(ApartmentAccessService::class)->canManage($actor)) {
            throw new AuthorizationException('Импорт доступен только администратору или глобальному менеджеру.');
        }

        $preview = $this->preview($jsonUpload, $mediaUpload);
        $duplicate = ApartmentTelegramImport::query()
            ->where('apartment_id', $apartment->getKey())
            ->where('sha256', $preview['sha256'])
            ->first();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'json_path' => 'Этот экспорт уже импортирован в систему для выбранной квартиры.',
            ]);
        }

        return DB::transaction(function () use ($apartment, $actor, $preview, $mediaUpload): array {
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

            $media = $this->mediaEntries($mediaUpload);
            $storedPhotos = 0;
            $storedDocuments = 0;
            $skipped = (int) $preview['skipped_count'];
            $order = 0;

            foreach ($rows as $row) {
                $entry = $this->findMedia($media, $row['media']);
                if ($entry === null) {
                    if ($row['media_reference'] !== null) {
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

    /** @param array{absolute_path:string,size:int,original_name:string} $upload */
    private function readExport(array $upload): array
    {
        if ($upload['size'] > self::MAX_JSON_BYTES) {
            throw ValidationException::withMessages(['json_path' => 'JSON-файл не найден или слишком большой.']);
        }

        try {
            $data = json_decode((string) file_get_contents($upload['absolute_path']), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['json_path' => 'Файл не является корректным Telegram JSON экспортом.']);
        }
        if (! is_array($data)) {
            throw ValidationException::withMessages(['json_path' => 'Ожидался JSON-объект Telegram Desktop.']);
        }

        if (array_key_exists('messages', $data) && ! is_array($data['messages'])) {
            throw ValidationException::withMessages(['json_path' => 'Поле messages должно быть массивом Telegram Desktop.']);
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
        $mediaField = null;
        $media = null;

        foreach (['photo', 'file', 'document', 'video', 'audio', 'voice', 'thumbnail'] as $field) {
            if (is_string($item[$field] ?? null) && trim($item[$field]) !== '') {
                $mediaField = $field;
                $media = trim($item[$field]);
                break;
            }
        }

        if ($text === '' && $media === null) {
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
            'media' => $this->isMediaPlaceholder($media) ? null : $media,
            'media_reference' => $media,
            'media_placeholder' => $this->isMediaPlaceholder($media),
            'media_kind' => $mediaField,
            'photo' => $mediaField === 'photo',
            'document' => in_array($mediaField, ['file', 'document'], true),
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
    private function mediaEntries(mixed $upload): array
    {
        if ($upload === null || $upload === '' || $upload === []) {
            return [];
        }

        $path = $this->resolveUpload($upload, 'media_path')['absolute_path'];
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

    /** @return array{absolute_path:string,size:int,original_name:string} */
    private function resolveUpload(mixed $upload, string $field): array
    {
        if (is_array($upload)) {
            $upload = collect($upload)->first(fn (mixed $value): bool => $value !== null && $value !== '');
        }

        if ($upload instanceof TemporaryUploadedFile || $upload instanceof UploadedFile) {
            $path = $upload->getRealPath();

            if (is_string($path) && is_file($path)) {
                return [
                    'absolute_path' => $path,
                    'size' => (int) $upload->getSize(),
                    'original_name' => $upload->getClientOriginalName(),
                ];
            }
        }

        if (is_string($upload) && $this->isAbsolutePath($upload) && is_file($upload)) {
            return [
                'absolute_path' => $upload,
                'size' => (int) filesize($upload),
                'original_name' => basename($upload),
            ];
        }

        if (is_string($upload) && $upload !== '') {
            $disk = Storage::disk('local');

            if ($disk->exists($upload)) {
                $path = $disk->path($upload);

                if (is_file($path)) {
                    return [
                        'absolute_path' => $path,
                        'size' => (int) $disk->size($upload),
                        'original_name' => basename($upload),
                    ];
                }
            }
        }

        throw ValidationException::withMessages([
            $field => $field === 'json_path'
                ? 'JSON-файл не найден или слишком большой.'
                : 'Архив media не найден или недоступен.',
        ]);
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2})/', $path) === 1
            || str_starts_with($path, '/');
    }

    private function isMediaPlaceholder(?string $value): bool
    {
        return $value !== null && str_starts_with(mb_strtolower(trim($value)), '(file not included');
    }

    private function dateSortValue(string $date): int
    {
        if ($date === '') {
            return PHP_INT_MAX;
        }

        if (is_numeric($date)) {
            return (int) $date;
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? PHP_INT_MAX : $timestamp;
    }
}
