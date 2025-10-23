<?php

namespace GregPriday\WorkManager\Support;

use GregPriday\WorkManager\Models\WorkOrder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\Enums\FilterOperator;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Centralized query builder configuration for WorkOrder filtering.
 *
 * This class provides a single source of truth for both HTTP API and MCP
 * tool filtering, ensuring consistent behavior across all interfaces.
 */
final class WorkOrderQuery
{
    /**
     * Create a configured QueryBuilder instance for WorkOrder queries.
     *
     * Supports filtering, sorting, including relations, and field selection.
     * Preloads items by default.
     *
     * @param  Request  $request  The HTTP request or synthetic request with query parameters
     * @return QueryBuilder The configured query builder
     */
    public static function make(Request $request): QueryBuilder
    {
        // Start from Eloquent query and preload items by default for backward compatibility
        $base = WorkOrder::query()->with(['items']);

        return QueryBuilder::for($base, $request)
            // Fields must be declared before includes (Spatie requirement)
            ->allowedFields([
                // Base model fields
                'work_orders.id',
                'work_orders.type',
                'work_orders.state',
                'work_orders.priority',
                'work_orders.requested_by_type',
                'work_orders.requested_by_id',
                'work_orders.created_at',
                'work_orders.updated_at',
                'work_orders.last_transitioned_at',
                'work_orders.applied_at',
                'work_orders.completed_at',
                'work_orders.payload',
                'work_orders.meta',

                // Relation fields (when relations are included)
                'items.id',
                'items.type',
                'items.state',
                'items.input',
                'items.result',
                'items.lease_expires_at',
                'items.leased_by_agent_id',
                'items.attempts',
                'items.max_attempts',
                'events.id',
                'events.event',
                'events.payload',
                'events.created_at',
                'events.actor_type',
                'events.actor_id',
            ])
            ->allowedIncludes([
                // Auto-adds counts/exists suffixes via AllowedInclude::relationship
                AllowedInclude::relationship('items'),
                AllowedInclude::relationship('events'),
                AllowedInclude::count('items'),
                AllowedInclude::count('events'),
            ])
            ->allowedFilters([
                // Exact filters
                AllowedFilter::exact('id'),
                AllowedFilter::exact('state'),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('requested_by_type'),
                AllowedFilter::exact('requested_by_id'),

                // Relational exact filters (dot notation)
                AllowedFilter::exact('items.state'),

                // Operator filters (numbers/dates). Use DYNAMIC to accept >,>=,<,<=
                AllowedFilter::operator('priority', FilterOperator::DYNAMIC),
                AllowedFilter::operator('created_at', FilterOperator::DYNAMIC),
                AllowedFilter::operator('last_transitioned_at', FilterOperator::DYNAMIC),
                AllowedFilter::operator('applied_at', FilterOperator::DYNAMIC),
                AllowedFilter::operator('completed_at', FilterOperator::DYNAMIC),

                // JSON contains filter on meta[...]
                AllowedFilter::callback('meta', function ($query, $value) {
                    // Allow: filter[meta]=batch_id:123 or a JSON-object
                    // Normalize string "key:value" → ['key'=>'value']
                    if (is_string($value) && str_contains($value, ':')) {
                        [$k, $v] = explode(':', $value, 2);
                        $value = [$k => is_numeric($v) ? +$v : $v];
                    }
                    $query->whereJsonContains('meta', $value);
                }),

                // "has available items" (agent discoverability use-case)
                AllowedFilter::callback('has_available_items', function ($query, $truthy) {
                    if ($truthy === false || $truthy === 'false' || $truthy === 0 || $truthy === '0') {
                        return;
                    }
                    $query->whereHas('items', function ($q) {
                        $q->where('state', 'queued')
                            ->where(function ($qq) {
                                $qq->whereNull('lease_expires_at')
                                    ->orWhere('lease_expires_at', '<=', now());
                            });
                    });
                }),
            ])
            ->allowedSorts([
                // Allow multi-sort and aliases
                AllowedSort::field('priority'),
                AllowedSort::field('created_at'),
                AllowedSort::field('last_transitioned_at'),
                AllowedSort::field('applied_at'),
                AllowedSort::field('completed_at'),

                // Sort by items_count requires a callback to ensure withCount
                AllowedSort::callback('items_count', function ($query, bool $descending) {
                    $query->withCount('items')
                        ->orderBy('items_count', $descending ? 'desc' : 'asc');
                }),
            ])
            // Keep existing default sort semantics: priority desc, created_at asc
            ->defaultSort('-priority', 'created_at');
    }
}
