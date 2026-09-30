<?php

namespace App\Services\Emergency;

use App\Models\Instruction;
use Illuminate\Support\Collection;
use RuntimeException;

class EmergencySnapshotBuilder
{
    public function build(): array
    {
        $instructions = Instruction::query()
            ->select([
                'id', 'instruction_category_id', 'title', 'short_description', 'blocks',
                'is_featured', 'is_emergency_safe', 'sort_order', 'status', 'is_public', 'published_at',
            ])
            ->where('status', 'published')
            ->where('is_public', true)
            ->where('is_emergency_safe', true)
            ->whereNotNull('published_at')
            ->where(function ($query): void {
                $query->whereNull('instruction_category_id')
                    ->orWhereHas('category', fn ($category) => $category->where('is_active', true));
            })
            ->with('category:id,title,sort_order,is_active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $groups = [];

        foreach ($instructions as $instruction) {
            $item = $this->instructionDto($instruction);
            $groupKey = $instruction->category
                ? 'category:'.$instruction->category->getKey()
                : 'uncategorized';
            $categoryTitle = $this->plainText($instruction->category?->title ?? 'Без категории');

            if ($this->containsSensitiveMaterial($categoryTitle)) {
                throw new RuntimeException('An Emergency TRIS instruction category requires additional content security review.');
            }

            $groups[$groupKey] ??= [
                '_sort' => $instruction->category?->sort_order ?? PHP_INT_MAX,
                'title' => $categoryTitle,
                'instructions' => [],
            ];
            $groups[$groupKey]['instructions'][] = $item;
        }

        foreach ($groups as &$group) {
            usort($group['instructions'], fn (array $left, array $right): int =>
                (($right['featured'] ?? false) <=> ($left['featured'] ?? false))
                ?: (($left['sort_order'] ?? 0) <=> ($right['sort_order'] ?? 0))
                ?: strcmp($left['title'], $right['title'])
            );
        }
        unset($group);

        uasort($groups, fn (array $left, array $right): int =>
            (($left['_sort'] ?? PHP_INT_MAX) <=> ($right['_sort'] ?? PHP_INT_MAX))
            ?: strcmp($left['title'], $right['title'])
        );

        foreach ($groups as &$group) {
            unset($group['_sort']);
        }
        unset($group);

        $generatedAt = now(config('app.timezone', 'Europe/Rome'))->toIso8601String();
        $content = [
            'status' => [
                'title' => '⚠ TRIS — аварийный режим',
                'message' => 'Основная система TRIS может быть временно недоступна. Здесь сохранены последние опубликованные инструкции.',
                'last_updated_label' => 'Данные актуальны на:',
            ],
            'instructions' => array_values($groups),
            'feedback' => [
                'endpoint' => $this->publicFeedbackEndpoint(),
                'types' => $this->safeOptions(config('emergency.feedback.types', [])),
                'areas' => $this->safeOptions(config('emergency.feedback.areas', [])),
                'urgencies' => $this->safeOptions(config('emergency.feedback.urgencies', [])),
            ],
            'freshness' => [
                'warning_hours' => max(1, (int) config('emergency.stale_warning_hours', 12)),
                'critical_hours' => max(2, (int) config('emergency.stale_critical_hours', 48)),
            ],
        ];
        $identity = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'schema_version' => 1,
            'snapshot_id' => 'emergency-'.str_replace([':', '+'], ['-', ''], $generatedAt).'-'.substr(hash('sha256', $identity), 0, 12),
            'generated_at' => $generatedAt,
            'timezone' => config('app.timezone', 'Europe/Rome'),
            'source' => config('app.name', 'TRIS Academy').' · '.(app()->environment('production') ? 'production' : 'non-production'),
            ...$content,
        ];
    }

    private function instructionDto(Instruction $instruction): array
    {
        $title = $this->plainText($instruction->title);
        $description = $this->plainText($instruction->short_description);
        $blocks = collect($instruction->blocks ?? [])
            ->map(fn (mixed $block): ?array => is_array($block) ? $this->blockDto($block) : null)
            ->filter()
            ->values()
            ->all();
        $plainContent = collect($blocks)
            ->flatMap(fn (array $block): Collection => $this->blockTexts($block))
            ->implode("\n");

        if ($this->containsSensitiveMaterial(implode("\n", [
            $title,
            $description,
            $plainContent,
        ]))) {
            throw new RuntimeException('An Emergency TRIS instruction requires additional content security review.');
        }

        return [
            'title' => $title,
            'short_description' => $description,
            'featured' => (bool) $instruction->is_featured,
            'sort_order' => (int) $instruction->sort_order,
            'blocks' => $blocks,
        ];
    }

    private function blockDto(array $block): ?array
    {
        $type = $block['type'] ?? null;
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        $title = $this->plainText($data['title'] ?? '');

        return match ($type) {
            'hero' => ['type' => 'hero', 'title' => $title, 'description' => $this->plainText($data['description'] ?? ''), 'badge' => $this->plainText($data['badge'] ?? '')],
            'text' => ['type' => 'text', 'title' => $title, 'content' => $this->plainText($data['content'] ?? '')],
            'warning' => ['type' => 'warning', 'title' => $title, 'content' => $this->plainText($data['content'] ?? ''), 'style' => in_array($data['type'] ?? '', ['info', 'warning', 'danger', 'success'], true) ? $data['type'] : 'warning'],
            'steps' => ['type' => 'steps', 'title' => $title, 'items' => $this->listItems($data['items'] ?? [], ['title', 'text'])],
            'checklist', 'tips' => ['type' => $type, 'title' => $title, 'items' => $this->listItems($data['items'] ?? [], ['text'])],
            'faq' => ['type' => 'faq', 'title' => $title, 'items' => $this->listItems($data['items'] ?? [], ['question', 'answer'])],
            'links' => ['type' => 'links', 'title' => $title, 'items' => $this->safeLinks($data['items'] ?? [])],
            default => null,
        };
    }

    private function listItems(mixed $items, array $keys): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)->take(100)->map(function (mixed $item) use ($keys): array {
            $item = is_array($item) ? $item : [];

            return collect($keys)->mapWithKeys(fn (string $key): array => [$key => $this->plainText($item[$key] ?? '')])->all();
        })->filter(fn (array $item): bool => collect($item)->contains(fn (string $text): bool => $text !== ''))->values()->all();
    }

    private function safeLinks(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $primaryHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return collect($items)->take(100)->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item) use ($primaryHost): ?array {
                $url = trim((string) ($item['url'] ?? ''));
                $parts = parse_url($url);

                if (! is_array($parts)
                    || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                    || blank($parts['host'] ?? null)
                    || isset($parts['user'])
                    || isset($parts['pass'])
                    || isset($parts['query'])
                    || isset($parts['fragment'])
                    || preg_match('/(?:token|secret|password|парол|код)/iu', $url) === 1
                    || preg_match('/(?:^|\/)[A-Za-z0-9_-]{24,}(?:\/|$)/u', (string) ($parts['path'] ?? '')) === 1
                    || ($primaryHost && strcasecmp((string) $parts['host'], (string) $primaryHost) === 0)) {
                    return null;
                }

                return ['label' => $this->plainText($item['label'] ?? 'Ссылка'), 'url' => $url];
            })
            ->filter(fn (?array $item): bool => $item !== null && $item['label'] !== '')
            ->values()
            ->all();
    }

    private function blockTexts(array $block): Collection
    {
        return match ($block['type']) {
            'steps', 'checklist', 'tips', 'faq' => collect($block['items'] ?? [])->flatMap(fn (array $item): array => array_values($item)),
            'links' => collect($block['items'] ?? [])->flatMap(fn (array $item): array => [$item['label'] ?? '', $item['url'] ?? '']),
            default => collect($block)->except('type')->values(),
        };
    }

    private function plainText(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = (string) $value;
        $text = preg_replace('/<(script|style|iframe|object|svg)[^>]*>.*?<\/\1\s*>/isu', '', $text) ?: $text;
        $text = preg_replace('/<\/(?:p|div|li|h[1-6]|section|tr|blockquote)>/iu', "\n", $text) ?: $text;
        $text = preg_replace('/<br\s*\/?\s*>/iu', "\n", $text) ?: $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', preg_replace('/\R{3,}/u', "\n\n", $text) ?: $text) ?: $text);
    }

    private function containsSensitiveMaterial(string $text): bool
    {
        return preg_match('/(?:\bпарол\p{L}*\b|\bpassword\b|\bтокен\p{L}*\b|\btoken\b|api[_ -]?key|secret|код.{0,24}(?:гост|доступ|замк|локер|вход|двер)|(?:гост|доступ|замк|локер).{0,24}код|pin[- ]?код|\bкарта\b.{0,35}\b\d{8,}\b)/iu', $text) === 1
            || preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', $text) === 1
            || preg_match('/(?:\+?\d[\d ()-]{8,}\d)/u', $text) === 1;
    }

    private function safeOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return collect($options)->filter(fn (mixed $option): bool => is_array($option))
            ->map(function (array $option): ?array {
                $value = trim((string) ($option['value'] ?? ''));
                $label = $this->plainText($option['label'] ?? '');

                if (preg_match('/^[a-z0-9_-]{1,32}$/i', $value) !== 1
                    || $label === ''
                    || $this->containsSensitiveMaterial($label)) {
                    return null;
                }

                return ['value' => $value, 'label' => $label];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function publicFeedbackEndpoint(): ?string
    {
        $endpoint = trim((string) config('emergency.feedback_endpoint'));
        $parts = parse_url($endpoint);
        $primaryHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || blank($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('/(?:token|secret|password|парол|код)/iu', $endpoint) === 1
            || preg_match('/(?:^|\/)[A-Za-z0-9_-]{24,}(?:\/|$)/u', (string) ($parts['path'] ?? '')) === 1
            || ($primaryHost && strcasecmp((string) $parts['host'], (string) $primaryHost) === 0)) {
            return null;
        }

        return $endpoint;
    }
}
