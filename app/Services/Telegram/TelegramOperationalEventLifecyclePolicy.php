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

        if (in_array('unanswered_question', $types, true)
            && ! $this->hasIndependentlyConfirmedDurableProblem($evidence)) {
            return false;
        }

        if ($evidence->contains(fn (TelegramOperationalEventEvidence $item): bool => in_array($item->role, ['report', 'recurrence'], true)) === false) {
            return false;
        }

        // These imperatives can be classified as quality issues because they
        // mention dirt, but they report no defect that persists across days.
        return ! $this->isStandaloneInstruction($summary);
    }

    private function hasIndependentlyConfirmedDurableProblem(Collection $evidence): bool
    {
        return $evidence->contains(function (TelegramOperationalEventEvidence $item): bool {
            if (! in_array($item->role, ['report', 'recurrence'], true)) {
                return false;
            }

            $observation = $item->observation;
            $message = $observation?->message;

            if (! $message || ! in_array($observation->reason_code, ['operational_problem', 'quality_issue'], true)) {
                return false;
            }

            $text = trim((string) ($message->text ?: $message->caption ?: ''));

            if ($text === '' || str_contains($text, '?')
                || preg_match('/^(?:кто|что|где|когда|как|почему|можно\s+ли|есть\s+ли)\b/ui', $text) === 1) {
                return false;
            }

            return preg_match('/(?:двер|замок|ключ|свет|подсвет|вытяж|ручк|полотен|пододеяль|бель|курьер|посуд|ванн|кухн|кран|труб|вода|вешалк|пульт|кондиционер|гостев\s+локер|коврик)/ui', $text) === 1
                && preg_match('/(?:не\s+работает|слом|брак|поврежд|протеч|подт[её]к|не\s+открыва|не\s+забрал|не\s+включа|грязн|отвал)/ui', $text) === 1;
        });
    }

    public function isStandaloneInstruction(string $text): bool
    {
        return preg_match(
            '/^\s*(?:(?:и\s+)?фото\s+загрязнен\S*\s+пожалуйста|оформи\s+пожалуйста\s+запрос\s+на\s+грязн\S*\s+квартир\S*|если\s+есть\s+грязн\S*\s+постельн\S*\s*,?\s*нужно\s+фото\s+прикрепить)\s*[.!?]*\s*$/iu',
            $text,
        ) === 1;
    }
}
