<?php

use App\Models\Apartment;
use App\Models\ApartmentInformationAttachment;
use App\Models\ApartmentInformationSection;
use App\Models\ApartmentTelegramImport;
use App\Models\User;
use App\Services\Apartments\TelegramApartmentImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function (): void {
    config(['database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    $schema = Schema::connection('sqlite');
    $schema->create('users', function (Blueprint $table): void {
        $table->id(); $table->string('name')->nullable(); $table->string('email')->nullable();
        $table->string('role')->default('admin'); $table->string('status')->default('approved');
        $table->boolean('is_active')->default(true); $table->timestamps();
    });
    $schema->create('apartments', function (Blueprint $table): void {
        $table->id(); $table->string('name'); $table->string('information_status')->default('published');
        $table->boolean('is_active')->default(true); $table->timestamp('information_updated_at')->nullable();
        $table->unsignedBigInteger('information_updated_by')->nullable(); $table->timestamps();
    });
    $schema->create('user_panel_accesses', function (Blueprint $table): void {
        $table->id(); $table->unsignedBigInteger('user_id'); $table->string('panel');
        $table->boolean('can_access')->default(false); $table->timestamps();
    });
    $schema->create('apartment_information_sections', function (Blueprint $table): void {
        $table->id(); $table->unsignedBigInteger('apartment_id'); $table->string('type'); $table->string('title');
        $table->text('content'); $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
        $table->unsignedBigInteger('created_by')->nullable(); $table->unsignedBigInteger('updated_by')->nullable(); $table->timestamps();
    });
    $schema->create('apartment_information_attachments', function (Blueprint $table): void {
        $table->id(); $table->unsignedBigInteger('apartment_id'); $table->unsignedBigInteger('information_section_id')->nullable();
        $table->string('disk')->default('local'); $table->string('path'); $table->string('original_name'); $table->string('mime_type');
        $table->unsignedBigInteger('file_size'); $table->string('caption')->nullable(); $table->unsignedInteger('sort_order')->default(0);
        $table->unsignedBigInteger('uploaded_by')->nullable(); $table->timestamps();
    });
    $schema->create('apartment_telegram_imports', function (Blueprint $table): void {
        $table->id(); $table->unsignedBigInteger('apartment_id'); $table->string('original_name'); $table->unsignedBigInteger('file_size')->default(0);
        $table->string('sha256', 64); $table->string('status')->default('draft'); $table->unsignedInteger('message_count')->default(0);
        $table->unsignedInteger('photo_count')->default(0); $table->unsignedInteger('document_count')->default(0); $table->unsignedInteger('skipped_count')->default(0);
        $table->unsignedBigInteger('imported_by')->nullable(); $table->timestamp('imported_at')->nullable(); $table->timestamps();
        $table->unique(['apartment_id', 'sha256']);
    });
    Storage::fake('local');
});

function telegramImportFixture(array $messages): string
{
    $path = tempnam(sys_get_temp_dir(), 'tris-telegram-').'.json';
    file_put_contents($path, json_encode(['name' => 'Work chat', 'messages' => $messages], JSON_THROW_ON_ERROR));
    return $path;
}

it('previews a Telegram Desktop export and ignores service and bot messages', function (): void {
    $path = telegramImportFixture([
        ['type' => 'message', 'date' => '2026-08-01T10:00:00', 'from' => 'Anna', 'text' => 'Проверить квартиру'],
        ['type' => 'service', 'action' => 'join', 'date' => '2026-08-01T10:01:00'],
        ['type' => 'message', 'date' => '2026-08-01T10:02:00', 'text' => '/start'],
        ['type' => 'message', 'date' => '2026-08-01T10:03:00', 'text' => ''],
    ]);

    $preview = app(TelegramApartmentImportService::class)->preview($path);

    expect($preview['message_count'])->toBe(1)
        ->and($preview['skipped_count'])->toBe(3)
        ->and($preview['samples'][0])->toContain('Проверить квартиру');
});

it('imports drafts only into the selected apartment and protects duplicates', function (): void {
    $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'status' => 'approved', 'is_active' => true]);
    $apartment = Apartment::create(['name' => 'A-1', 'information_status' => 'published', 'is_active' => true]);
    $other = Apartment::create(['name' => 'A-2', 'information_status' => 'published', 'is_active' => true]);
    $path = telegramImportFixture([
        ['type' => 'message', 'date' => '2026-08-01T10:00:00', 'from' => 'Anna', 'text' => 'Черновая заметка'],
    ]);

    $service = app(TelegramApartmentImportService::class);
    $result = $service->import($apartment, $path, null, $admin);

    expect($result['message_count'])->toBe(1)
        ->and(ApartmentInformationSection::query()->where('apartment_id', $apartment->id)->where('is_visible', false)->count())->toBe(1)
        ->and(ApartmentTelegramImport::query()->where('apartment_id', $apartment->id)->count())->toBe(1)
        ->and(ApartmentInformationSection::query()->where('apartment_id', $other->id)->count())->toBe(0)
        ->and($apartment->fresh()->information_status)->toBe('published');

    expect(fn () => $service->import($apartment, $path, null, $admin))->toThrow(ValidationException::class);
    expect(ApartmentInformationAttachment::query()->count())->toBe(0);
});

it('stores supported photo and PDF media as private draft attachments', function (): void {
    $admin = User::create(['name' => 'Admin', 'email' => 'admin-media@example.test', 'role' => 'admin', 'status' => 'approved', 'is_active' => true]);
    $apartment = Apartment::create(['name' => 'Media apartment', 'information_status' => 'published', 'is_active' => true]);
    $json = telegramImportFixture([
        ['type' => 'message', 'date' => '2026-08-01T10:00:00', 'from' => 'Anna', 'photo' => 'photos/one.jpg', 'text' => 'Фото'],
        ['type' => 'message', 'date' => '2026-08-01T10:01:00', 'from' => 'Anna', 'file' => 'docs/one.pdf', 'text' => 'Документ'],
    ]);
    $zipPath = tempnam(sys_get_temp_dir(), 'tris-media-').'.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('photos/one.jpg', "\xFF\xD8\xFF\xE0".str_repeat('x', 12));
    $zip->addFromString('docs/one.pdf', "%PDF-1.4\ncontent");
    $zip->close();

    $result = app(TelegramApartmentImportService::class)->import($apartment, $json, $zipPath, $admin);

    expect($result['photo_count'])->toBe(1)
        ->and($result['document_count'])->toBe(1)
        ->and(ApartmentInformationAttachment::query()->count())->toBe(2)
        ->and(ApartmentInformationAttachment::query()->pluck('disk')->unique()->all())->toBe(['local'])
        ->and(Storage::disk('local')->allFiles('apartment-information/'.$apartment->id))->toHaveCount(2);
});

it('blocks import for an ordinary user', function (): void {
    $cleaner = User::create(['name' => 'Cleaner', 'email' => 'cleaner@example.test', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true]);
    $apartment = Apartment::create(['name' => 'Restricted apartment', 'information_status' => 'published', 'is_active' => true]);
    $path = telegramImportFixture([
        ['type' => 'message', 'date' => '2026-08-01T10:00:00', 'text' => 'Не импортировать'],
    ]);

    expect(fn () => app(TelegramApartmentImportService::class)->import($apartment, $path, null, $cleaner))
        ->toThrow(AuthorizationException::class);
});
