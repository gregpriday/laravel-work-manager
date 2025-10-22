<?php

namespace GregPriday\WorkManager\Routing;

use GregPriday\WorkManager\Http\Controllers\WorkOrderApiController;
use Illuminate\Contracts\Routing\Registrar;

class RouteRegistrar
{
    public function __construct(
        protected Registrar $router
    ) {
    }

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

                // Checkout (lease) next work item
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

                // Release work item lease
                $router->post('/items/{item}/release', [WorkOrderApiController::class, 'release'])
                    ->name('work-manager.release');

                // Get item logs/events
                $router->get('/items/{item}/logs', [WorkOrderApiController::class, 'logs'])
                    ->name('work-manager.logs');
            });
    }
}
