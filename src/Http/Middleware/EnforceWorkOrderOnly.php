<?php

namespace GregPriday\WorkManager\Http\Middleware;

use Closure;
use GregPriday\WorkManager\Exceptions\ForbiddenDirectMutationException;
use GregPriday\WorkManager\Models\WorkOrder;
use Illuminate\Http\Request;

class EnforceWorkOrderOnly
{
    /**
     * Handle an incoming request.
     *
     * Ensures that mutations are only performed through valid work orders.
     */
    public function handle(Request $request, Closure $next, ...$allowedStates)
    {
        // Check for work order context in request
        $orderId = $request->header('X-Work-Order-ID')
            ?? $request->input('_work_order_id');

        if (! $orderId) {
            throw new ForbiddenDirectMutationException(
                'This action requires a valid work order context. Please create a work order first.'
            );
        }

        // Verify the work order exists and is in an allowed state
        $order = WorkOrder::find($orderId);

        if (! $order) {
            throw new ForbiddenDirectMutationException(
                'The specified work order does not exist.'
            );
        }

        // Check state if allowed states are specified
        if (! empty($allowedStates)) {
            $currentState = $order->state->value;

            if (! in_array($currentState, $allowedStates)) {
                throw new ForbiddenDirectMutationException(
                    'Work order must be in one of these states: '.implode(', ', $allowedStates).
                    ". Current state: {$currentState}"
                );
            }
        }

        // Attach order to request for downstream use
        $request->merge(['_work_order' => $order]);

        return $next($request);
    }
}
