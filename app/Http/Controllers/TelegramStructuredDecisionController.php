<?php

namespace App\Http\Controllers;

use App\Models\StructuredRequestDecision;
use App\Services\Forms\StructuredRequestReview;
use App\Services\RolePreviewContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class TelegramStructuredDecisionController extends Controller
{
    public function __construct(private readonly StructuredRequestReview $review) {}

    private function context(Request $request, string $kind, int $id): array
    {
        app(RolePreviewContext::class)->assertWritable();
        $record = $this->review->find($kind, $id);
        $actor = $request->user();
        $telegram = ! $request->routeIs('structured.admin.*');
        abort_unless($this->review->canReview($actor) && $this->review->hasSource($kind, $record), 403);
        if (! $telegram) {
            abort_unless($actor->isAdmin(), 403);
            return [$record, false, null];
        }
        abort_unless($this->review->enabled($kind), 403);
        $grant = $request->session()->get('structured_review_grant');
        abort_unless(is_array($grant) && ($grant['kind'] ?? null) === $kind
            && (int) ($grant['request_id'] ?? 0) === $id
            && (int) ($grant['actor_id'] ?? 0) === (int) $actor->id
            && ($grant['expires_at'] ?? 0) >= now()->timestamp
            && is_string($grant['fingerprint'] ?? null), 403, 'Откройте заявку в Telegram заново.');
        return [$record, true, $grant['fingerprint']];
    }

    public function show(Request $request, string $kind, int $requestId): Response
    {
        [$record, $telegram, $grantedFingerprint] = $this->context($request, $kind, $requestId);
        abort_unless($this->review->canOpen($kind, $record, $request->user(), $telegram), 409, 'Заявка уже рассмотрена.');
        $rows = $this->review->rows($kind, $record);
        $fingerprint = $this->review->fingerprint($kind, $record, $rows);
        abort_if($telegram && ! hash_equals($grantedFingerprint, $fingerprint), 409, 'Заявка уже изменена. Откройте её заново.');
        return $this->page($kind, $record, $telegram, $fingerprint, $this->review->snapshot($kind, $rows));
    }

    public function store(Request $request, string $kind, int $requestId): Response
    {
        [$record, $telegram, $grantedFingerprint] = $this->context($request, $kind, $requestId);
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
            'items' => ['required', 'array'],
            'items.*' => ['required', 'array'],
            'items.*.qty' => [$kind === 'inventory' ? 'required' : 'prohibited', 'integer', 'min:0'],
            'items.*.choice' => [$kind === 'inventory' ? 'prohibited' : 'required', 'in:approved,rejected'],
            'items.*.reason' => ['nullable', 'string', 'max:600'],
            'comment' => ['nullable', 'string', 'max:1800'],
        ]);
        if ($telegram && ! hash_equals($grantedFingerprint, $data['fingerprint'])) {
            throw ValidationException::withMessages(['items' => 'Откройте заявку в Telegram заново.']);
        }
        $decision = $this->review->decide($kind, $record, $request->user(), $data['fingerprint'], $data['items'], $data['comment'] ?? null, $telegram);
        return $this->page($kind, $record->fresh(), $telegram, $data['fingerprint'], $decision->snapshot['items'], $decision);
    }

    private function page(string $kind, Model $record, bool $telegram, string $fingerprint, array $items, ?StructuredRequestDecision $decision = null): Response
    {
        $record->loadMissing('user');
        return response()->view('telegram.structured-decision', [
            'kind' => $kind, 'record' => $record, 'telegram' => $telegram,
            'fingerprint' => $fingerprint, 'items' => $items, 'decision' => $decision,
            'title' => $this->review->label($kind),
            'formAction' => route($telegram ? 'structured.decision.store' : 'structured.admin.store', [$kind, $record->id]),
            'resourceUrl' => $this->review->resourceUrl($kind, $record->id),
            'pickupMessage' => StructuredRequestReview::PICKUP_MESSAGE,
            'pickupAddress' => StructuredRequestReview::PICKUP_ADDRESS,
            'pickupUrl' => StructuredRequestReview::PICKUP_URL,
        ])->header('Cache-Control', 'private, no-store');
    }
}
