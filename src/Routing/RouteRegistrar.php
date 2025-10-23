<?php

namespace GregPriday\WorkManager\Routing;

use GregPriday\WorkManager\Http\Controllers\WorkOrderApiController;
use Illuminate\Contracts\Routing\Registrar;

/**
 * Registers all work manager HTTP routes (propose/checkout/submit/etc).
 *
 * @internal Called via WorkManager::routes() facade.
 *
 * @see docs/reference/routes-reference.md
 */
class RouteRegistrar
{
    public function __construct(
        protected Registrar $router
    ) {}

    /**
     * Register the work manager routes.
     */
    public function register(string $basePath = 'agent/work', array $middleware = ['api']): void
    {
        $this->router->prefix($basePath)
            ->middleware($middleware)
            ->group(function (Registrar $router) {
                // Propose a new work order
                $router->post('/propose', [WorkOrderApiController::class, 'propose'])
                    ->name('work-manager.propose');

                // List work orders
                $router->get('/orders', [WorkOrderApiController::class, 'index'])
                    ->name('work-manager.index');

                // Show a specific order
                $router->get('/orders/{order}', [WorkOrderApiController::class, 'show'])
                    ->name('work-manager.show');

                // Global checkout (lease) - next available item across all orders
                $router->post('/checkout', [WorkOrderApiController::class, 'checkoutGlobal'])
                    ->name('work-manager.checkout-global');

                // Checkout (lease) next work item from specific order
                $router->post('/orders/{order}/checkout', [WorkOrderApiController::class, 'checkout'])
                    ->name('work-manager.checkout');

                // Approve an order
                $router->post('/orders/{order}/approve', [WorkOrderApiController::class, 'approve'])
                    ->name('work-manager.approve');

                // Reject an order
                $router->post('/orders/{order}/reject', [WorkOrderApiController::class, 'reject'])
                    ->name('work-manager.reject');

                // Heartbeat (extend lease)
                $router->post('/items/{item}/heartbeat', [WorkOrderApiController::class, 'heartbeat'])
                    ->name('work-manager.heartbeat');

                // Submit work item results
                $router->post('/items/{item}/submit', [WorkOrderApiController::class, 'submit'])
                    ->name('work-manager.submit');

                // Submit work item part (partial submission)
                $router->post('/items/{item}/parts', [WorkOrderApiController::class, 'submitPart'])
                    ->name('work-manager.submit-part');

                // List work item parts
                $router->get('/items/{item}/parts', [WorkOrderApiController::class, 'listParts'])
                    ->name('work-manager.list-parts');

                // Finalize work item (assemble parts)
                $router->post('/items/{item}/finalize', [WorkOrderApiController::class, 'finalize'])
                    ->name('work-manager.finalize');

                // Release work item lease
                $router->post('/items/{item}/release', [WorkOrderApiController::class, 'release'])
                    ->name('work-manager.release');

                // Get item logs/events
                $router->get('/items/{item}/logs', [WorkOrderApiController::class, 'logs'])
                    ->name('work-manager.logs');
            });
    }
}
