<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryRequest extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'comment',
        'admin_comment',
        'requested_at',
        'reviewed_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryRequestItem::class)->orderBy('id');
    }

    public function refreshStatusFromItems(): void
    {
        $items = $this->items()->get();

        if ($items->isEmpty()) {
            $this->update([
                'status' => 'pending',
            ]);

            return;
        }

        $approvedCount = $items->where('status', 'approved')->count();
        $rejectedCount = $items->where('status', 'rejected')->count();
        $pendingCount = $items->where('status', 'pending')->count();

        if ($pendingCount > 0) {
            $this->update([
                'status' => 'pending',
            ]);

            return;
        }

        if ($approvedCount > 0 && $rejectedCount === 0) {
            $this->update([
                'status' => 'approved',
                'reviewed_at' => now(),
            ]);

            return;
        }

        if ($rejectedCount > 0 && $approvedCount === 0) {
            $this->update([
                'status' => 'rejected',
                'reviewed_at' => now(),
            ]);

            return;
        }

        $this->update([
            'status' => 'partially_approved',
            'reviewed_at' => now(),
        ]);
    }
}