<?php

namespace App\Services\Learning;

use App\Models\Instruction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LearningCatalog
{
    public const COURSE_SLUG = 'instructions';

    public function lessons(User $user): Builder
    {
        return Instruction::query()
            ->where('status', 'published')
            ->where('is_public', true)
            ->where(fn (Builder $query) => $query->whereNull('instruction_category_id')
                ->orWhereHas('category', fn (Builder $category) => $category->where('is_active', true)))
            ->when($user->status !== 'approved' || ! $user->is_active, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->orderBy('sort_order')->orderBy('id');
    }

    public function progress(User $user, Collection $lessons): array
    {
        $records = DB::table('learning_lesson_progress')->where('user_id', $user->id)
            ->whereIn('instruction_id', $lessons->modelKeys())->get()->keyBy('instruction_id');
        $completed = $records->whereNotNull('completed_at')->count();
        $lastOpened = $records->whereNull('completed_at')->sortByDesc('last_opened_at')->first();
        $resume = $lessons->firstWhere('id', $lastOpened?->instruction_id)
            ?? $lessons->first(fn (Instruction $lesson) => ! $records->get($lesson->id)?->completed_at);

        return [
            'records' => $records,
            'completed' => $completed,
            'total' => $lessons->count(),
            'percent' => $lessons->isEmpty() ? 0 : (int) floor($completed / $lessons->count() * 100),
            'resume' => $resume,
        ];
    }

    public function record(User $user, Instruction $lesson, bool $complete = false): void
    {
        $now = now();
        $identity = ['user_id' => $user->id, 'instruction_id' => $lesson->id];
        DB::table('learning_lesson_progress')->insertOrIgnore($identity + [
            'last_opened_at' => $now, 'completed_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('learning_lesson_progress')->where($identity)->update(['last_opened_at' => $now, 'updated_at' => $now]);
        if ($complete) {
            DB::table('learning_lesson_progress')->where($identity)->whereNull('completed_at')->update(['completed_at' => $now]);
        }
    }
}
