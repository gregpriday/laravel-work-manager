<?php

namespace GregPriday\WorkManager\Models;

use GregPriday\WorkManager\Support\OrderState;
use GregPriday\WorkManager\Support\ActorType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    use HasUuids;

    protected $fillable = [
        'type',
        'state',
        'priority',
        'requested_by_type',
        'requested_by_id',
        'payload',
        'meta',
        'acceptance_config',
        'applied_at',
        'completed_at',
        'last_transitioned_at',
    ];

    protected $casts = [
        'state' => OrderState::class,
        'requested_by_type' => ActorType::class,
        'payload' => 'array',
        'meta' => 'array',
        'acceptance_config' => 'array',
        'applied_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_transitioned_at' => 'datetime',
        'priority' => 'integer',
    ];

    /**
     * Get the work items for this order.
     */
    public function items(): HasMany
    {
        return $this->hasMany(WorkItem::class, 'order_id');
    }

    /**
     * Get the events for this order.
     */
    public function events(): HasMany
    {
        return $this->hasMany(WorkEvent::class, 'order_id');
    }

    /**
     * Get the provenance records for this order.
     */
    public function provenances(): HasMany
    {
        return $this->hasMany(WorkProvenance::class, 'order_id');
    }

    /**
     * Check if the order is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return $this->state->isTerminal();
    }

    /**
     * Check if all items are complete.
     */
    public function allItemsComplete(): bool
    {
        return $this->items()
            ->whereNotIn('state', ['completed', 'rejected', 'dead_lettered'])
            ->doesntExist();
    }

    /**
     * Scope to filter by state.
     */
    public function scopeInState($query, OrderState|string $state)
    {
        $stateValue = $state instanceof OrderState ? $state->value : $state;

        return $query->where('state', $stateValue);
    }

    /**
     * Scope to filter by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get orders requested by a specific actor.
     */
    public function scopeRequestedBy($query, ActorType|string $type, ?string $id = null)
    {
        $typeValue = $type instanceof ActorType ? $type->value : $type;
        $query->where('requested_by_type', $typeValue);

        if ($id !== null) {
            $query->where('requested_by_id', $id);
        }

        return $query;
    }
}
