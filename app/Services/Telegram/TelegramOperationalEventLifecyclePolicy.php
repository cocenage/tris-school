<?php

namespace App\Services\Telegram;

use App\Models\TelegramOperationalEventEvidence;
use Illuminate\Support\Collection;

class TelegramOperationalEventLifecyclePolicy
{
    /**
     * The ledger status records what was observed. Carry-over is a separate
     * presentation decision about whether an old open event still matters.
     *
     * @param  array<int, string>  $types
     * @param  Collection<int, TelegramOperationalEventEvidence>  $evidence
     */
    public function mayCarryOver(array $types, string $summary, Collection $evidence): bool
    {
        if (collect($types)->intersect(['problem', 'quality_issue'])->isEmpty()) {
            return false;
        }

        if ($evidence->contains(fn (TelegramOperationalEventEvidence $item): bool => in_array($item->role, ['report', 'recurrence'], true)) === false) {
            return false;
        }

        // These imperatives can be classified as quality issues because they
        // mention dirt, but they report no defect that persists across days.
        return ! $this->isStandaloneInstruction($summary);
    }

    public function isStandaloneInstruction(string $text): bool
    {
        return preg_match(
            '/^\s*(?:(?:и\s+)?фото\s+загрязнен\S*\s+пожалуйста|оформи\s+пожалуйста\s+запрос\s+на\s+грязн\S*\s+квартир\S*|если\s+есть\s+грязн\S*\s+постельн\S*\s*,?\s*нужно\s+фото\s+прикрепить)\s*[.!?]*\s*$/iu',
            $text,
        ) === 1;
    }
}
