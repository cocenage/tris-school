<?php

use App\Models\Apartment;
use App\Models\ApartmentInformationAttachment;
use App\Models\ApartmentUserAccess;
use App\Models\User;
use App\Services\Apartments\ApartmentAccessService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');

    $schema = Schema::connection('sqlite');
    $schema->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('telegram_username')->nullable();
        $table->string('role')->default('cleaner');
        $table->string('status')->default('approved');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
    $schema->create('apartments', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('code')->nullable();
        $table->string('address')->nullable();
        $table->string('image')->nullable();
        $table->text('notes')->nullable();
        $table->boolean('is_active')->default(true);
        $table->string('information_status')->default('published');
        $table->timestamp('information_updated_at')->nullable();
        $table->unsignedBigInteger('information_updated_by')->nullable();
        $table->timestamps();
    });
    $schema->create('control_responses', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id')->nullable();
        $table->unsignedBigInteger('cleaner_id')->nullable();
        $table->unsignedBigInteger('supervisor_id')->nullable();
        $table->timestamps();
    });
    $schema->create('user_panel_accesses', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('panel');
        $table->boolean('can_access')->default(false);
        $table->timestamps();
    });
    $schema->create('apartment_user_access', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('granted_by')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->unique(['apartment_id', 'user_id']);
    });
    foreach (['day_off_requests', 'vacation_requests', 'inventory_requests', 'salary_questions', 'schedule_questions', 'feedback_suggestions'] as $tableName) {
        $schema->create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('answer_seen_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->text('admin_comment')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }
    $schema->create('apartment_information_sections', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->string('type', 40);
        $table->string('title');
        $table->text('content');
        $table->unsignedInteger('sort_order')->default(0);
        $table->boolean('is_visible')->default(true);
        $table->unsignedBigInteger('created_by')->nullable();
        $table->unsignedBigInteger('updated_by')->nullable();
        $table->timestamps();
    });
    $schema->create('apartment_information_attachments', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->unsignedBigInteger('information_section_id')->nullable();
        $table->string('disk')->default('local');
        $table->string('path');
        $table->string('original_name');
        $table->string('mime_type');
        $table->unsignedBigInteger('file_size');
        $table->string('caption')->nullable();
        $table->unsignedInteger('sort_order')->default(0);
        $table->unsignedBigInteger('uploaded_by')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    Storage::fake('local');
    DB::purge('sqlite');
});

function apartmentTestUser(string $role, string $name): User
{
    return User::create([
        'name' => $name,
        'email' => strtolower($role) . '-' . uniqid() . '@example.test',
        'role' => $role,
        'status' => 'approved',
        'is_active' => true,
    ]);
}

function apartmentTestRecord(string $name, string $status = 'published', bool $active = true): Apartment
{
    return Apartment::create(['name' => $name, 'address' => 'Test address', 'is_active' => $active, 'information_status' => $status]);
}

function grantApartmentAccess(Apartment $apartment, User $user, ?Carbon $expiresAt = null, ?User $grantedBy = null): ApartmentUserAccess
{
    return ApartmentUserAccess::create([
        'apartment_id' => $apartment->id,
        'user_id' => $user->id,
        'expires_at' => $expiresAt,
        'granted_by' => $grantedBy?->id,
    ]);
}

it('shows all apartments to admins and never derives staff access from old controls', function () {
    $admin = apartmentTestUser('admin', 'Admin');
    $cleaner = apartmentTestUser('cleaner', 'Cleaner');
    $supervisor = apartmentTestUser('supervisor', 'Supervisor');
    $first = apartmentTestRecord('First');
    $second = apartmentTestRecord('Second');
    $draft = apartmentTestRecord('Draft', 'draft');

    DB::table('control_responses')->insert([
        ['apartment_id' => $first->id, 'cleaner_id' => $cleaner->id, 'supervisor_id' => $supervisor->id],
        ['apartment_id' => $second->id, 'cleaner_id' => null, 'supervisor_id' => $supervisor->id],
    ]);

    $access = app(ApartmentAccessService::class);
    expect($access->visibleQuery($admin)->pluck('id')->all())->toHaveCount(3)
        ->and($access->visibleQuery($cleaner)->pluck('id')->all())->toBe([])
        ->and($access->visibleQuery($supervisor)->pluck('id')->all())->toBe([]);

    grantApartmentAccess($first, $cleaner, null, $admin);
    expect($access->visibleQuery($cleaner)->pluck('id')->all())->toBe([$first->id]);
});

it('allows only active explicit grants and blocks drafts for staff', function () {
    $admin = apartmentTestUser('admin', 'Admin');
    $cleaner = apartmentTestUser('cleaner', 'Cleaner');
    $published = apartmentTestRecord('Published');
    $draft = apartmentTestRecord('Draft', 'draft');
    grantApartmentAccess($published, $cleaner, null, $admin);
    grantApartmentAccess($draft, $cleaner, null, $admin);

    $this->actingAs($cleaner)->get(route('page-apartments'))->assertOk();
    $this->actingAs($cleaner)->get(route('page-apartments.show', $published))->assertOk();
    $this->actingAs($cleaner)->get(route('page-apartments.show', $draft))->assertForbidden();
    $this->actingAs($admin)->get(route('page-apartments.show', $draft))->assertOk();
});

it('supports indefinite and expiry-based access and revocation immediately', function () {
    $admin = apartmentTestUser('admin', 'Admin');
    $cleaner = apartmentTestUser('cleaner', 'Cleaner');
    $indefinite = apartmentTestRecord('Indefinite');
    $expired = apartmentTestRecord('Expired');
    grantApartmentAccess($indefinite, $cleaner, null, $admin);
    grantApartmentAccess($expired, $cleaner, now('Europe/Rome')->subDay(), $admin);
    $access = app(ApartmentAccessService::class);

    expect($access->canView($cleaner, $indefinite))->toBeTrue()
        ->and($access->canView($cleaner, $expired))->toBeFalse();

    $grant = $indefinite->accessGrants()->first();
    $access->revoke($admin, $grant);
    expect($access->canView($cleaner, $indefinite))->toBeFalse();
    $this->actingAs($cleaner)->get(route('page-apartments.show', $indefinite))->assertForbidden();
});

it('prevents non-managers from granting access and upserts duplicate grants', function () {
    $admin = apartmentTestUser('admin', 'Admin');
    $cleaner = apartmentTestUser('cleaner', 'Cleaner');
    $other = apartmentTestUser('cleaner', 'Other');
    $apartment = apartmentTestRecord('Apartment');
    $service = app(ApartmentAccessService::class);

    expect(fn () => $service->grant($cleaner, $apartment, $other))->toThrow(AuthorizationException::class);
    $first = $service->grant($admin, $apartment, $cleaner, now('Europe/Rome')->addDay());
    $second = $service->grant($admin, $apartment, $cleaner, now('Europe/Rome')->addDays(5));
    expect(ApartmentUserAccess::query()->count())->toBe(1)
        ->and($second->fresh()->expires_at->isFuture())->toBeTrue()
        ->and($first->id)->toBe($second->id);
});

it('supports bulk-style grants without duplicates', function () {
    $admin = apartmentTestUser('admin', 'Admin');
    $one = apartmentTestUser('cleaner', 'One');
    $two = apartmentTestUser('supervisor', 'Two');
    $apartment = apartmentTestRecord('Bulk apartment');
    $service = app(ApartmentAccessService::class);

    foreach ([$one, $two, $one] as $user) {
        $service->grant($admin, $apartment, $user);
    }

    expect(ApartmentUserAccess::query()->where('apartment_id', $apartment->id)->count())->toBe(2);
});

it('protects an apartment with active access from partial deletion', function () {
    $admin = apartmentTestUser('admin', 'Admin');
    $cleaner = apartmentTestUser('cleaner', 'Cleaner');
    $apartment = apartmentTestRecord('Protected apartment');
    grantApartmentAccess($apartment, $cleaner, null, $admin);

    expect(fn () => $apartment->delete())->toThrow(RuntimeException::class);
    expect(Apartment::query()->whereKey($apartment->id)->exists())->toBeTrue();
});

it('lets an administrator inspect drafts and records section authorship', function () {
    $admin = apartmentTestUser('admin', 'Admin');
    $draft = apartmentTestRecord('Draft apartment', 'draft');
    $this->actingAs($admin)->get(route('page-apartments.show', $draft))->assertOk();
    $section = $draft->informationSections()->create(['type' => 'access', 'title' => 'Доступ', 'content' => 'Тестовый текст', 'sort_order' => 1, 'is_visible' => true]);
    expect($section->created_by)->toBe($admin->id)
        ->and($section->updated_by)->toBe($admin->id)
        ->and($draft->fresh()->information_updated_by)->toBe($admin->id)
        ->and($draft->fresh()->information_updated_at)->not->toBeNull();
});

it('streams private attachments only through an authorized apartment route', function () {
    Storage::fake('local');
    $admin = apartmentTestUser('admin', 'Admin');
    $cleaner = apartmentTestUser('cleaner', 'Cleaner');
    $apartment = apartmentTestRecord('Attachment apartment');
    $other = apartmentTestRecord('Other apartment');
    grantApartmentAccess($apartment, $cleaner, null, $admin);
    Storage::disk('local')->put('apartment-information/test.pdf', 'safe test file');
    $attachment = ApartmentInformationAttachment::create(['apartment_id' => $apartment->id, 'disk' => 'local', 'path' => 'apartment-information/test.pdf', 'original_name' => 'test.pdf', 'mime_type' => 'application/pdf', 'file_size' => 14]);

    $this->actingAs($cleaner)->get(route('page-apartments.attachment', [$apartment, $attachment]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($cleaner)->get(route('page-apartments.attachment', [$other, $attachment]))->assertNotFound();
});

it('escapes section content instead of rendering arbitrary html', function () {
    $admin = apartmentTestUser('admin', 'Admin');
    $apartment = apartmentTestRecord('Safe content apartment');
    $apartment->informationSections()->create(['type' => 'general', 'title' => 'Описание', 'content' => '<script>alert(1)</script>']);
    $response = $this->actingAs($admin)->get(route('page-apartments.show', $apartment));
    $response->assertOk();
    expect($response->getContent())->not->toContain('<script>alert(1)</script>');
});
