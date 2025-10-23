<?php

namespace GregPriday\WorkManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agent metadata and request fingerprints for auditability.
 *
 * @extends \Illuminate\Database\Eloquent\Model<self>
 *
 * @property string $agent_name
 * @property string|null $agent_version
 * @property string|null $request_fingerprint
 * @property array<string,mixed>|null $extra
 * @property-read WorkOrder|null $order
 * @property-read WorkItem|null $item
 *
 * @method static \Illuminate\Database\Eloquent\Builder|static byAgent(string $agentName)
 *
 * @see docs/concepts/lifecycle-and-flow.md
 */
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
