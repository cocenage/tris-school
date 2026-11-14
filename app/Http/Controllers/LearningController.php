<?php

namespace App\Http\Controllers;

use App\Services\Learning\LearningCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LearningController extends Controller
{
    public function index(Request $request, LearningCatalog $catalog): View
    {
        abort_unless($request->user()->is_active, 403);
        $lessons = $catalog->lessons($request->user())->get();

        return view('learning.index', ['progress' => $catalog->progress($request->user(), $lessons)]);
    }

    public function show(Request $request, LearningCatalog $catalog, string $course): View
    {
        abort_unless($request->user()->is_active, 403);
        abort_unless($course === LearningCatalog::COURSE_SLUG, 404);
        $lessons = $catalog->lessons($request->user())->get();

        return view('learning.course', ['lessons' => $lessons, 'progress' => $catalog->progress($request->user(), $lessons)]);
    }

    public function lesson(Request $request, LearningCatalog $catalog, string $course, string $lesson): View
    {
        abort_unless($course === LearningCatalog::COURSE_SLUG, 404);
        $instruction = $catalog->lessons($request->user())->where('slug', $lesson)->firstOrFail();
        $catalog->record($request->user(), $instruction);
        $lessons = $catalog->lessons($request->user())->get();
        $progress = $catalog->progress($request->user(), $lessons);

        return view('learning.lesson', compact('instruction', 'progress'));
    }

    public function complete(Request $request, LearningCatalog $catalog, string $course, string $lesson): RedirectResponse
    {
        abort_unless($course === LearningCatalog::COURSE_SLUG, 404);
        $instruction = $catalog->lessons($request->user())->where('slug', $lesson)->firstOrFail();
        $catalog->record($request->user(), $instruction, true);
        $progress = $catalog->progress($request->user(), $catalog->lessons($request->user())->get());

        return $progress['resume']
            ? redirect()->route('learning.lesson', ['course' => $course, 'lesson' => $progress['resume']->slug])
            : redirect()->route('learning.show', $course);
    }
}
