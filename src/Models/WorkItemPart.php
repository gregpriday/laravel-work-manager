<?php

namespace GregPriday\WorkManager\Models;

use GregPriday\WorkManager\Support\PartStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkItemPart extends Model
{
    use HasUuids;

    protected $fillable = [
        'work_item_id',
        'part_key',
        'seq',
        'status',
        'payload',
        'evidence',
        'notes',
        'errors',
        'checksum',
        'submitted_by_agent_id',
        'idempotency_key_hash',
    ];

    protected $casts = [
        'status' => PartStatus::class,
        'seq' => 'integer',
        'payload' => 'array',
        'evidence' => 'array',
        'errors' => 'array',
    ];

    /**
     * Get the work item that owns this part.
     */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_item_id');
    }

    /**
     * Check if the part is validated.
     */
    public function isValidated(): bool
    {
        return $this->status === PartStatus::VALIDATED;
    }

    /**
     * Check if the part is rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === PartStatus::REJECTED;
    }

    /**
     * Generate a checksum for the payload.
     */
    public function generateChecksum(): string
    {
        return hash('sha256', json_encode($this->payload));
    }

    /**
     * Scope to get validated parts only.
     */
    public function scopeValidated($query)
    {
        return $query->where('status', PartStatus::VALIDATED->value);
    }

    /**
     * Scope to get parts for a specific key.
     */
    public function scopeForKey($query, string $key)
    {
        return $query->where('part_key', $key);
    }

    /**
     * Scope to get the latest part for each key (by sequence number, with id as tiebreaker).
     * This returns a collection, not a query builder.
     */
    public function scopeLatestPerKey($query, string $itemId)
    {
        // Get all part keys first
        $partKeys = static::where('work_item_id', $itemId)
            ->distinct()
            ->pluck('part_key');

        $latestPartIds = [];

        // For each part_key, get the latest part by seq (or id if seq is same/null)
        foreach ($partKeys as $partKey) {
            $latestPart = static::where('work_item_id', $itemId)
                ->where('part_key', $partKey)
                ->orderByRaw('seq IS NULL ASC, seq DESC, id DESC')
                ->first();

            if ($latestPart) {
                $latestPartIds[] = $latestPart->id;
            }
        }

        return $query->whereIn('id', $latestPartIds);
    }
}
