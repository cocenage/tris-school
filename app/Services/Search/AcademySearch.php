<?php

namespace App\Services\Search;

use App\Models\Instruction;
use App\Models\User;
use App\Services\Apartments\ApartmentAccessService;
use App\Services\Learning\LearningCatalog;
use Illuminate\Support\Str;

class AcademySearch
{
    public function normalize(string $query): string
    {
        return mb_strtolower(Str::squish($query));
    }

    public function search(?User $user, string $query): array
    {
        $query = $this->normalize($query);
        if (! $user?->isApproved() || ! $user->is_active || mb_strlen($query) < 2 || mb_strlen($query) > 120) {
            return [];
        }

        $results = [];
        // Stream authorized records and match in PHP so Cyrillic case folding also works on SQLite.
        $instructions = Instruction::query()->where('status', 'published')->where('is_public', true)->orderBy('id');
        $this->appendMatches($results, $instructions->cursor(), $query, 'Инструкции',
            fn ($item) => [$item->title, $item->short_description, json_encode($item->blocks, JSON_UNESCAPED_UNICODE)],
            fn ($item) => route('page-home.instructions.single', $item->slug));

        $apartments = app(ApartmentAccessService::class)->visibleQuery($user)->orderBy('id');
        $this->appendMatches($results, $apartments->cursor(), $query, 'Квартиры',
            fn ($item) => [$item->name, $item->code, $item->address],
            fn ($item) => route('page-apartments.show', $item->id), 'name');

        $lessons = app(LearningCatalog::class)->lessons($user);
        $this->appendMatches($results, $lessons->cursor(), $query, 'Обучение',
            fn ($item) => [$item->title, $item->short_description, json_encode($item->blocks, JSON_UNESCAPED_UNICODE)],
            fn ($item) => route('learning.lesson', ['course' => LearningCatalog::COURSE_SLUG, 'lesson' => $item->slug]));

        return $results;
    }

    private function appendMatches(array &$results, iterable $items, string $query, string $section, callable $text, callable $url, string $title = 'title'): void
    {
        $count = 0;
        foreach ($items as $item) {
            if (! str_contains(mb_strtolower(implode(' ', $text($item))), $query)) {
                continue;
            }

            $results[] = ['section' => $section, 'title' => $item->{$title}, 'url' => $url($item)];
            if (++$count === 10) {
                break;
            }
        }
    }
}
