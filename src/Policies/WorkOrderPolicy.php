<?php

namespace GregPriday\WorkManager\Policies;

use GregPriday\WorkManager\Models\WorkOrder;
use Illuminate\Foundation\Auth\User;

/**
 * Authorization policy for work orders (propose/view/approve/reject).
 *
 * @internal Integrates with Laravel Gate system.
 *
 * @see docs/concepts/security-and-permissions.md
 */
class WorkOrderPolicy
{
    /**
     * Determine if the user can propose new work orders.
     */
    public function propose(?User $user): bool
    {
        // Check against configured gate/permission
        $ability = config('work-manager.policies.propose');

        if ($user && method_exists($user, 'can')) {
            return $user->can($ability);
        }

        // Default: allow if authenticated
        return $user !== null;
    }

    /**
     * Determine if the user can view the work order.
     */
    public function view(?User $user, WorkOrder $order): bool
    {
        if (! $user) {
            return false;
        }

        // Allow if user is the requester
        if ($order->requested_by_id === (string) $user->id) {
            return true;
        }

        // Check for explicit viewing permission via configured gate
        $ability = config('work-manager.policies.view');

        if ($ability && method_exists($user, 'can')) {
            return $user->can($ability);
        }

        // Default deny for security - apps must explicitly grant view permissions
        // to non-requesters (e.g., admins, supervisors, same-tenant users)
        return false;
    }

    /**
     * Determine if the user can checkout work items.
     */
    public function checkout(?User $user, WorkOrder $order): bool
    {
        $ability = config('work-manager.policies.checkout');

        if ($user && method_exists($user, 'can')) {
            return $user->can($ability);
        }

        return $user !== null;
    }

    /**
     * Determine if the user can submit work item results.
     */
    public function submit(?User $user, WorkOrder $order): bool
    {
        $ability = config('work-manager.policies.submit');

        if ($user && method_exists($user, 'can')) {
            return $user->can($ability);
        }

        return $user !== null;
    }

    /**
     * Determine if the user can approve work orders.
     */
    public function approve(?User $user, WorkOrder $order): bool
    {
        $ability = config('work-manager.policies.approve');

        if ($user && method_exists($user, 'can')) {
            return $user->can($ability);
        }

        // Default: only authenticated users with approval permission
        return false;
    }

    /**
     * Determine if the user can reject work orders.
     */
    public function reject(?User $user, WorkOrder $order): bool
    {
        $ability = config('work-manager.policies.reject');

        if ($user && method_exists($user, 'can')) {
            return $user->can($ability);
        }

        // Default: only authenticated users with rejection permission
        return false;
    }
}
