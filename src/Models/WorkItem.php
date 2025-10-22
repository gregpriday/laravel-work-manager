<?php

namespace GregPriday\WorkManager\Models;

use GregPriday\WorkManager\Support\ItemState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id',
        'type',
        'state',
        'attempts',
        'max_attempts',
        'leased_by_agent_id',
        'lease_expires_at',
        'last_heartbeat_at',
        'input',
        'result',
        'error',
        'accepted_at',
    ];

    protected $casts = [
        'state' => ItemState::class,
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'lease_expires_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'accepted_at' => 'datetime',
        'input' => 'array',
        'result' => 'array',
        'error' => 'array',
    ];

    /**
     * Get the order that owns this item.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'order_id');
    }

    /**
     * Get the events for this item.
     */
    public function events(): HasMany
    {
        return $this->hasMany(WorkEvent::class, 'item_id');
    }

    /**
     * Get the provenance records for this item.
     */
    public function provenances(): HasMany
    {
        return $this->hasMany(WorkProvenance::class, 'item_id');
    }

    /**
     * Check if the item is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return $this->state->isTerminal();
    }

    /**
     * Check if the lease has expired.
     */
    public function isLeaseExpired(): bool
    {
        if (!$this->lease_expires_at) {
            return false;
        }

        return $this->lease_expires_at->isPast();
    }

    /**
     * Check if the item is currently leased.
     */
    public function isLeased(): bool
    {
        return $this->leased_by_agent_id !== null
            && $this->lease_expires_at !== null
            && !$this->isLeaseExpired();
    }

    /**
     * Check if max attempts have been reached.
     */
    public function hasExhaustedAttempts(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }

    /**
     * Scope to filter by state.
     */
    public function scopeInState($query, ItemState|string $state)
    {
        $stateValue = $state instanceof ItemState ? $state->value : $state;

        return $query->where('state', $stateValue);
    }

    /**
     * Scope to get items with expired leases.
     */
    public function scopeWithExpiredLease($query)
    {
        return $query->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now())
            ->whereNotIn('state', ['completed', 'dead_lettered']);
    }

    /**
     * Scope to get items available for leasing.
     */
    public function scopeAvailableForLease($query)
    {
        return $query->where('state', 'queued')
            ->where(function ($q) {
                $q->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<', now());
            });
    }

    /**
     * Scope to get items leased by a specific agent.
     */
    public function scopeLeasedBy($query, string $agentId)
    {
        return $query->where('leased_by_agent_id', $agentId)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '>', now());
    }
}
