<?php

use App\Http\Controllers\ApartmentAttachmentController;
use App\Http\Controllers\ControlMediaController;
use App\Http\Controllers\FormGuideController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\RolePreviewController;
use App\Http\Controllers\TelegramAnalyticsWebhookController;
use App\Http\Controllers\TelegramAuthController;
use App\Http\Controllers\TelegramLoginWidgetController;
use App\Http\Controllers\TelegramWorkWebhookController;
use App\Http\Controllers\TelegramWriteAccessController;
use App\Http\Middleware\SafeguardRolePreviewData;
use App\Models\Instruction;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $previewParameters = request()->query('loader_preview') === '1'
        ? ['loader_preview' => '1']
        : [];

    if (! auth()->check()) {
        return redirect()->route('landing.page', $previewParameters);
    }

    return redirect()->route(match (auth()->user()->status) {
        'approved' => 'page-home',
        'pending' => 'access.pending',
        'rejected' => 'access.rejected',
        default => 'landing.page',
    }, $previewParameters);
})->name('landing');

Route::livewire('/login', 'landing')->name('landing.page');

Route::post('/telegram/auth', TelegramAuthController::class)
    ->name('telegram.auth');

Route::get('/telegram/login', TelegramLoginWidgetController::class)
    ->name('telegram.login.widget');

Route::post('/telegram/write-access', TelegramWriteAccessController::class)
    ->middleware('auth')
    ->name('telegram.write-access');

Route::post('/logout', LogoutController::class)
    ->middleware('auth')
    ->name('logout');

Route::livewire('/access/pending', 'access.pending')
    ->middleware('auth')
    ->name('access.pending');

Route::livewire('/access/rejected', 'access.rejected')
    ->middleware('auth')
    ->name('access.rejected');

Route::get('/instruction/obiazatelnye-foto', function () {
    $instruction = Instruction::query()
        ->where('slug', 'novyi-spisok-obiazatelnyx-foto')
        ->where('status', 'published')
        ->where('is_public', true)
        ->firstOrFail();

    return redirect()->route('page-home.instructions.single', $instruction->slug, 301);
});

Route::middleware(['auth', 'approved'])->group(function () {
    Route::post('/ui/role-preview', [RolePreviewController::class, 'store'])
        ->name('role-preview.store');
    Route::post('/ui/role-preview/exit', [RolePreviewController::class, 'clear'])
        ->name('role-preview.clear');

    Route::get('/knowledge', [KnowledgeController::class, 'roadmap'])->name('knowledge.roadmap');
    Route::get('/knowledge/entities', [KnowledgeController::class, 'catalog'])->name('knowledge.entities');
    Route::get('/knowledge/entities/{entity}', [KnowledgeController::class, 'show'])->name('knowledge.show');

    Route::livewire('/home', 'page-home')->name('page-home');

    Route::livewire('/home/instructions', 'home.page-instructions')
        ->name('page-home.instructions');

    Route::livewire('/home/instructions/{instruction:slug}', 'home.page-instructions-single')
        ->name('page-home.instructions.single');

    Route::livewire('/checks', 'page-checks')->name('page-checks');
    Route::get('/coachings', function () {
        return redirect()->route(in_array(Auth::user()?->role, ['admin', 'supervisor'], true)
            ? 'page-coachings.create'
            : 'page-coachings.history');
    })->name('page-coachings');
    Route::livewire('/coachings/history', 'page-coachings')->middleware(SafeguardRolePreviewData::class)->name('page-coachings.history');
    Route::livewire('/coachings/create', 'forms.page-coaching-create')->middleware(SafeguardRolePreviewData::class)->name('page-coachings.create');
    Route::livewire('/coachings/{coaching}', 'profile.page-coaching-result')->middleware(SafeguardRolePreviewData::class)->whereNumber('coaching')->name('page-coachings.show');
    Route::get('/checks/control/draft-media/{control}/{room}/{question}/{media}', [ControlMediaController::class, 'draft'])
        ->middleware(SafeguardRolePreviewData::class)
        ->whereNumber(['room', 'question', 'media'])
        ->name('page-checks.control.draft-media');
    Route::get('/checks/control/result-media/{controlResponse}/{room}/{question}/{media}', [ControlMediaController::class, 'result'])
        ->middleware(SafeguardRolePreviewData::class)
        ->whereNumber(['room', 'question', 'media'])
        ->name('page-checks.control.result-media');
    Route::livewire('/checks/control', 'forms.page-control')->middleware(SafeguardRolePreviewData::class)->name('page-checks.control');
    Route::livewire('/checks/control/focus', 'forms.page-control-focus')->middleware(SafeguardRolePreviewData::class)->name('page-checks.control.focus');

    Route::livewire('/applications', 'page-applications')->name('page-applications');
    Route::livewire('/applications/weekend', 'forms.page-weekend')->name('page-applications.weekend');
    Route::livewire('/applications/vacation', 'forms.page-vacation')->name('page-applications.vacation');
    Route::livewire('/applications/inventory', 'forms.page-inventory')->name('page-applications.inventory');
    Route::livewire('/applications/salary', 'forms.page-salary')->name('page-applications.salary');
    Route::livewire('/applications/schedule', 'forms.page-schedule')->name('page-applications.schedule');
    Route::livewire('/applications/feedback', 'forms.page-feedback')->name('page-applications.feedback');

    Route::post('/form-guides/seen', [FormGuideController::class, 'store'])
        ->name('form-guides.seen');

    Route::livewire('/profile', 'page-profile')->name('page-profile');
    Route::livewire('/profile/checks', 'profile.page-checks')->middleware(SafeguardRolePreviewData::class)->name('page-profile.checks');
    Route::livewire('/profile/checks/{controlResponse}', 'profile.page-check-result')->middleware(SafeguardRolePreviewData::class)->name('page-profile.checks.result');
    Route::livewire('/profile/all-checks', 'profile.page-all-checks')->middleware(SafeguardRolePreviewData::class)->name('page-profile.all-checks');
    Route::livewire('/profile/employees/{employee}/history', 'profile.page-employee-history')->middleware(SafeguardRolePreviewData::class)->whereNumber('employee')->name('page-profile.employee-history');
    Route::livewire('/profile/applications', 'profile.page-applications')->middleware(SafeguardRolePreviewData::class)->name('page-profile.applications');

    Route::livewire('/apartments', 'apartments.page-apartments')->name('page-apartments');
    Route::livewire('/apartments/{apartment}', 'apartments.page-apartment')->name('page-apartments.show');
    Route::get('/apartments/{apartment}/attachments/{attachment}', ApartmentAttachmentController::class)
        ->name('page-apartments.attachment');

    Route::livewire('/tasks', 'tasks.page-tasks')
        ->middleware(SafeguardRolePreviewData::class)
        ->name('page-tasks');

    Route::livewire('/tasks/calendar', 'tasks.page-calendar')
        ->middleware(SafeguardRolePreviewData::class)
        ->name('page-tasks.calendar');

    Route::livewire('/tasks/rooms', 'tasks.page-rooms')
        ->middleware(SafeguardRolePreviewData::class)
        ->name('page-tasks.rooms');

    Route::livewire('/tasks/rooms/{taskRoom}', 'tasks.page-room')
        ->middleware(SafeguardRolePreviewData::class)
        ->name('page-tasks.room');

    Route::livewire('/tasks/boards/{taskBoard}', 'tasks.page-board')
        ->middleware(SafeguardRolePreviewData::class)
        ->name('page-tasks.board');

    Route::livewire('/tasks/{task}', 'tasks.page-task')
        ->middleware(SafeguardRolePreviewData::class)
        ->name('page-tasks.show');

    Route::get('/profile/calendar', function () {
        return redirect()->route('page-tasks.calendar');
    })->name('page-profile.calendar');
});

Route::post('/telegram/analytics-webhook/{secret}', TelegramAnalyticsWebhookController::class)
    ->withoutMiddleware([
        VerifyCsrfToken::class,
    ])
    ->name('telegram.analytics-webhook');

Route::post('/telegram/work-webhook/{secret}', TelegramWorkWebhookController::class)
    ->withoutMiddleware([
        VerifyCsrfToken::class,
    ])
    ->name('telegram.work-webhook');

Route::fallback(function () {
    if (! Auth::check()) {
        return redirect()->route('landing.page');
    }

    return redirect()->route(match (Auth::user()->status) {
        'approved' => 'page-home',
        'pending' => 'access.pending',
        'rejected' => 'access.rejected',
        default => 'landing.page',
    });

});
