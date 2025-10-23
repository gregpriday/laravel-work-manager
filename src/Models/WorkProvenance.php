<?php

namespace GregPriday\WorkManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkProvenance extends Model
{
    const UPDATED_AT = null; // Only created_at timestamp

    protected $fillable = [
        'order_id',
        'item_id',
        'idempotency_key_hash',
        'agent_version',
        'agent_name',
        'request_fingerprint',
        'extra',
    ];

    protected $casts = [
        'extra' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Get the order that this provenance belongs to.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'order_id');
    }

    /**
     * Get the item that this provenance belongs to.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'item_id');
    }

    /**
     * Scope to filter by agent.
     */
    public function scopeByAgent($query, string $agentName)
    {
        return $query->where('agent_name', $agentName);
    }
}
