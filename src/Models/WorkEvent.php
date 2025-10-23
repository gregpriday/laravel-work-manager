<?php

namespace GregPriday\WorkManager\Models;

use GregPriday\WorkManager\Support\ActorType;
use GregPriday\WorkManager\Support\EventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail for lifecycle transitions and actions.
 *
 * @extends \Illuminate\Database\Eloquent\Model<self>
 *
 * @property EventType $event
 * @property ActorType $actor_type
 * @property array<string,mixed>|null $payload
 * @property array<string,mixed>|null $diff
 * @property-read WorkOrder|null $order
 * @property-read WorkItem|null $item
 *
 * @method static \Illuminate\Database\Eloquent\Builder|static ofType(EventType|string $type)
 * @method static \Illuminate\Database\Eloquent\Builder|static byActor(ActorType|string $type, ?string $id = null)
 *
 * @see docs/reference/events-reference.md
 */
class WorkEvent extends Model
{
    const UPDATED_AT = null; // Only created_at timestamp

    protected $fillable = [
        'order_id',
        'item_id',
        'event',
        'actor_type',
        'actor_id',
        'payload',
        'diff',
        'message',
    ];

    protected $casts = [
        'event' => EventType::class,
        'actor_type' => ActorType::class,
        'payload' => 'array',
        'diff' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Get the order that this event belongs to.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'order_id');
    }

    /**
     * Get the item that this event belongs to.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'item_id');
    }

    /**
     * Scope to filter by event type.
     */
    public function scopeOfType($query, EventType|string $type)
    {
        $typeValue = $type instanceof EventType ? $type->value : $type;

        return $query->where('event', $typeValue);
    }

    /**
     * Scope to filter by actor.
     */
    public function scopeByActor($query, ActorType|string $type, ?string $id = null)
    {
        $typeValue = $type instanceof ActorType ? $type->value : $type;
        $query->where('actor_type', $typeValue);

        if ($id !== null) {
            $query->where('actor_id', $id);
        }

        return $query;
    }

    /**
     * Scope to get recent events.
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }
}
