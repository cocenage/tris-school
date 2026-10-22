<?php

use App\Models\Apartment;
use App\Models\ApartmentInformationAttachment;
use App\Models\ApartmentInformationSection;
use App\Models\ApartmentTelegramImport;
use App\Models\User;
use App\Services\Apartments\ApartmentBatchImportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('role');
        $table->string('status');
        $table->boolean('is_active');
        $table->timestamp('approved_at')->nullable();
        $table->timestamps();
    });
    foreach ([
        '2026_04_20_185400_create_apartments_table.php',
        '2026_08_02_100000_add_information_fields_to_apartments_table.php',
        '2026_08_02_100100_create_apartment_information_sections_table.php',
        '2026_08_02_100200_create_apartment_information_attachments_table.php',
        '2026_08_08_120000_create_apartment_telegram_imports_table.php',
    ] as $migration) {
        (require database_path('migrations/'.$migration))->up();
    }
    Storage::fake('local');
    $this->actor = User::create(['name' => 'Fixture admin', 'role' => 'admin', 'status' => 'approved', 'is_active' => true]);
    Storage::disk('local')->put('batch/export.json', json_encode([
        'name' => 'Fixture apartment', 'messages' => [['id' => 1, 'text' => 'Instruction']],
    ]));
    $this->entry = ['json' => Storage::disk('local')->path('batch/export.json'), 'name' => 'Fixture apartment', 'address' => 'Fixture Street 1'];
});

it('previews without writes and repeats without duplicating or overwriting apartment data', function () {
    $service = app(ApartmentBatchImportService::class);
    expect($service->preview([$this->entry])['counts']['new'])->toBe(1)
        ->and(Apartment::count())->toBe(0);
    $service->import([$this->entry], $this->actor);
    $apartment = Apartment::firstOrFail();
    $apartment->update(['notes' => 'Keep manually edited data', 'name' => 'Manual name']);
    $before = $apartment->fresh()->getAttributes();
    expect($service->preview([$this->entry])['counts']['matched'])->toBe(1);
    $service->import([$this->entry], $this->actor);
    expect(Apartment::count())->toBe(1)
        ->and(ApartmentInformationSection::count())->toBe(1)
        ->and($apartment->fresh()->getAttributes())->toBe($before);
});

it('reports ambiguous addresses and blocks the entire batch before writing', function () {
    Apartment::create(['name' => 'A', 'address' => 'Fixture Street 1']);
    Apartment::create(['name' => 'B', 'address' => 'Fixture Street 1']);
    $service = app(ApartmentBatchImportService::class);
    expect($service->preview([$this->entry])['counts']['ambiguous'])->toBe(1);
    expect(fn () => $service->import([$this->entry], $this->actor))->toThrow(ValidationException::class);
    expect(ApartmentInformationSection::count())->toBe(0);
});

it('rejects multi-chat exports and missing media without partial imports', function () {
    Storage::disk('local')->put('batch/export.json', json_encode(['chats' => ['list' => [['messages' => []], ['messages' => []]]]]));
    $service = app(ApartmentBatchImportService::class);
    expect($service->preview([$this->entry])['counts']['error'])->toBe(1);
    Storage::disk('local')->put('batch/export.json', json_encode(['messages' => [['text' => 'Photo', 'photo' => 'photos/missing.jpg']]]));
    expect($service->preview([$this->entry])['counts']['error'])->toBe(1)
        ->and(Apartment::count())->toBe(0);
});

it('adds only a hidden draft to an explicitly matched existing apartment', function () {
    $apartment = Apartment::create(['name' => 'Existing', 'address' => 'Fixture Street 1', 'notes' => 'Keep']);
    $service = app(ApartmentBatchImportService::class);
    $before = $apartment->fresh()->only(['name', 'address', 'notes', 'information_status', 'is_active']);
    expect($service->preview([$this->entry])['counts']['update'])->toBe(1);
    $service->import([$this->entry], $this->actor);
    expect($apartment->fresh()->only(array_keys($before)))->toBe($before)
        ->and(ApartmentInformationSection::first()->is_visible)->toBeFalse();
});

it('rejects changed exports and cross-apartment reuse instead of duplicating history', function () {
    $service = app(ApartmentBatchImportService::class);
    $service->import([$this->entry], $this->actor);
    $other = Apartment::create(['name' => 'Other', 'address' => 'Other street']);
    expect($service->preview([['json' => $this->entry['json'], 'apartment_id' => $other->id]])['counts']['error'])->toBe(1);
    Storage::disk('local')->put('batch/export.json', json_encode(['messages' => [['id' => 1, 'text' => 'Instruction'], ['id' => 2, 'text' => 'More']]]));
    expect($service->preview([$this->entry])['counts']['error'])->toBe(1)
        ->and(ApartmentInformationSection::count())->toBe(1);
});

it('retains authorization and prevents competing batch imports', function () {
    $service = app(ApartmentBatchImportService::class);
    $this->actor->update(['status' => 'pending']);
    expect(fn () => $service->import([$this->entry], $this->actor))->toThrow(AuthorizationException::class);
    $this->actor->update(['status' => 'approved']);
    $lock = Cache::lock('apartments:telegram-batch-import', 60);
    $lock->get();
    try {
        expect(fn () => $service->import([$this->entry], $this->actor))->toThrow(ValidationException::class);
        expect(Apartment::count())->toBe(0);
    } finally {
        $lock->release();
    }
});

it('imports media privately and skips it on a repeat', function (bool $failStorage) {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $path = Storage::disk('local')->path('batch/media.zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('photos/image.png', $png);
    $zip->close();
    Storage::disk('local')->put('batch/export.json', json_encode(['messages' => [['text' => 'Photo', 'photo' => 'photos/image.png']]]));
    $entry = $this->entry + ['media' => $path];
    $service = app(ApartmentBatchImportService::class);
    expect($service->preview([$entry])['rows'][0]['media'])->toBe(1);
    if ($failStorage) {
        $disk = Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldReceive('put')->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        expect(fn () => $service->import([$entry], $this->actor))->toThrow(RuntimeException::class);
        expect(Apartment::count())->toBe(0)
            ->and(ApartmentTelegramImport::count())->toBe(0);

        return;
    }
    $service->import([$entry], $this->actor);
    $service->import([$entry], $this->actor);
    $file = ApartmentInformationAttachment::firstOrFail();
    expect(ApartmentInformationAttachment::count())->toBe(1)
        ->and($file->disk)->toBe('local')
        ->and(Storage::disk('local')->get($file->path))->toBe($png);
})->with([false, true]);

it('runs the local command as preview by default and requires explicit apply and actor', function () {
    Storage::disk('local')->put('batch/manifest.json', json_encode([$this->entry]));
    $path = Storage::disk('local')->path('batch/manifest.json');
    $this->artisan('apartments:telegram-import', ['manifest' => $path])->assertSuccessful();
    expect(Apartment::count())->toBe(0);
    $this->artisan('apartments:telegram-import', ['manifest' => $path, '--apply' => true])->assertFailed();
    expect(Apartment::count())->toBe(0);
    $this->artisan('apartments:telegram-import', ['manifest' => $path, '--apply' => true, '--actor' => $this->actor->id])->assertSuccessful();
    expect(Apartment::count())->toBe(1);
});
