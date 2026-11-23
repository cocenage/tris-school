<?php

use App\Models\User;
use App\Services\Antigoccia\DeleteOwnAntigocciaReport;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Schema::dropIfExists('antigoccia_reports');
    Schema::dropIfExists('antigoccia_applications');
    Schema::create('antigoccia_applications', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->date('applied_on');
    });
    Schema::create('antigoccia_reports', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->unsignedBigInteger('user_id')->nullable();
        $table->uuid('submission_uuid');
        $table->json('photos')->nullable();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('antigoccia_reports');
    Schema::dropIfExists('antigoccia_applications');
});

function antigocciaTestActor(int $id, string $status = 'approved'): User
{
    $user = new User(['name' => 'Клинер '.$id, 'role' => 'cleaner', 'status' => $status, 'is_active' => true]);
    $user->id = $id;

    return $user;
}

it('deletes only the owner response and private photos, leaving the coating date intact', function (): void {
    $uuid = '6ea57da3-71dd-4374-b232-fdf249f2b9c1';
    $ownPhoto = 'antigoccia/10/'.$uuid.'/upload/mirror.jpg';
    $otherPhoto = 'antigoccia/10/another/upload/shower.jpg';
    Storage::disk('local')->put($ownPhoto, 'photo');
    Storage::disk('local')->put($otherPhoto, 'other');
    DB::table('antigoccia_applications')->insert(['apartment_id' => 10, 'applied_on' => '2026-11-10']);
    DB::table('antigoccia_reports')->insert([
        ['id' => 1, 'apartment_id' => 10, 'user_id' => 101, 'submission_uuid' => $uuid,
            'photos' => json_encode(['mirrors' => $ownPhoto])],
        ['id' => 2, 'apartment_id' => 10, 'user_id' => 202,
            'submission_uuid' => '6ea57da3-71dd-4374-b232-fdf249f2b9c2',
            'photos' => json_encode(['shower_inside' => $otherPhoto])],
    ]);

    app(DeleteOwnAntigocciaReport::class)->delete(antigocciaTestActor(101), 1);

    expect(DB::table('antigoccia_reports')->pluck('id')->all())->toBe([2])
        ->and(DB::table('antigoccia_applications')->count())->toBe(1);
    Storage::disk('local')->assertMissing($ownPhoto);
    Storage::disk('local')->assertExists($otherPhoto);
});

it('refuses attempts to delete other users responses', function (): void {
    DB::table('antigoccia_reports')->insert([
        'id' => 5, 'apartment_id' => 10, 'user_id' => 202,
        'submission_uuid' => '6ea57da3-71dd-4374-b232-fdf249f2b9c5',
        'photos' => null,
    ]);

    expect(fn () => app(DeleteOwnAntigocciaReport::class)->delete(antigocciaTestActor(101), 5))
        ->toThrow(ModelNotFoundException::class);
    expect(DB::table('antigoccia_reports')->count())->toBe(1);
});

it('refuses inactive and unapproved accounts', function (): void {
    $pending = antigocciaTestActor(101, 'pending');
    expect(fn () => app(DeleteOwnAntigocciaReport::class)->delete($pending, 1))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
