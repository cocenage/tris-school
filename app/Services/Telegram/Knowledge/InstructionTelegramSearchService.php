<?php

namespace App\Services\Knowledge;

use App\Models\Instruction;
use Illuminate\Support\Str;

class InstructionTelegramSearchService
{
    public function findForText(?string $text): ?Instruction
    {
        $text = Str::of($text ?? '')
            ->lower()
            ->replace(['?', '!', '.', ',', ':', ';'], ' ')
            ->squish()
            ->toString();

        if ($text === '') {
            return null;
        }

        return Instruction::query()
            ->where('status', 'published')
            ->where('is_public', true)
            ->get()
            ->first(function (Instruction $instruction) use ($text) {
                $keywords = collect(explode(',', (string) $instruction->telegram_keywords))
                    ->map(fn ($keyword) => trim(mb_strtolower($keyword)))
                    ->filter();

                foreach ($keywords as $keyword) {
                    if ($keyword !== '' && str_contains($text, $keyword)) {
                        return true;
                    }
                }

                return false;
            });
    }
}