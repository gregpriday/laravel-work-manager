<?php

use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\AbstractAcceptancePolicy;
use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Stub policy for testing
 */
class TestAcceptancePolicy extends AbstractAcceptancePolicy
{
    public bool $customApprovalResult = true;
    public $customValidationCallback = null;
    public bool $shouldCallFail = false;

    protected function validationRules(WorkItem $item): array
    {
        return [
            'status' => 'required|in:success,failed',
            'count' => 'required|integer|min:1',
        ];
    }

    protected function validationMessages(): array
    {
        return [
            'status.required' => 'The status field is mandatory',
            'status.in' => 'Status must be either success or failed',
        ];
    }

    protected function customValidation(WorkItem $item, array $result): void
    {
        if ($this->shouldCallFail) {
            $this->fail('data', 'INVALID_FORMAT', 'Data format is incorrect');
        }

        if ($this->customValidationCallback) {
            ($this->customValidationCallback)($item, $result);
        }
    }

    protected function customApprovalCheck(WorkOrder $order): bool
    {
        return $this->customApprovalResult;
    }
}

/**
 * Policy with empty validation rules
 */
class EmptyRulesPolicy extends AbstractAcceptancePolicy
{
    protected function validationRules(WorkItem $item): array
    {
        return [];
    }
}

it('validates submission successfully with valid data', function () {
    $order = WorkOrder::create(['type' => 't', 'state' => OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::SUBMITTED, 'input' => []]);

    $policy = new TestAcceptancePolicy();
    $result = ['status' => 'success', 'count' => 5];

    // Should not throw
    $policy->validateSubmission($item, $result);

    expect(true)->toBeTrue(); // If we got here, validation passed
});

it('throws ValidationException when validation rules fail', function () {
    $order = WorkOrder::create(['type' => 't', 'state' => OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::SUBMITTED, 'input' => []]);

    $policy = new TestAcceptancePolicy();
    $result = ['status' => 'invalid', 'count' => 0]; // Invalid status and count too low

    $policy->validateSubmission($item, $result);
})->throws(ValidationException::class);

it('throws ValidationException when required field is missing', function () {
    $order = WorkOrder::create(['type' => 't', 'state' => OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::SUBMITTED, 'input' => []]);

    $policy = new TestAcceptancePolicy();
    $result = ['count' => 5]; // Missing 'status'

    $policy->validateSubmission($item, $result);
})->throws(ValidationException::class);

it('uses custom validation messages', function () {
    $order = WorkOrder::create(['type' => 't', 'state' => OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::SUBMITTED, 'input' => []]);

    $policy = new TestAcceptancePolicy();
    $result = []; // Missing required fields

    try {
        $policy->validateSubmission($item, $result);
        expect(false)->toBeTrue(); // Should not reach here
    } catch (ValidationException $e) {
        $errors = $e->errors();
        expect($errors)->toHaveKey('status')
            ->and($errors['status'][0])->toBe('The status field is mandatory');
    }
});

it('calls customValidation hook', function () {
    $order = WorkOrder::create(['type' => 't', 'state' => OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::SUBMITTED, 'input' => []]);

    $called = false;
    $policy = new TestAcceptancePolicy();
    $policy->customValidationCallback = function ($passedItem, $passedResult) use ($item, &$called) {
        expect($passedItem->id)->toBe($item->id);
        expect($passedResult)->toHaveKey('status');
        $called = true;
    };

    $result = ['status' => 'success', 'count' => 5];
    $policy->validateSubmission($item, $result);

    expect($called)->toBeTrue();
});

it('allows custom validation to throw exceptions', function () {
    $order = WorkOrder::create(['type' => 't', 'state' => OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::SUBMITTED, 'input' => []]);

    $policy = new TestAcceptancePolicy();
    $policy->customValidationCallback = function () {
        throw ValidationException::withMessages(['custom' => ['Custom error']]);
    };

    $result = ['status' => 'success', 'count' => 5];

    $policy->validateSubmission($item, $result);
})->throws(ValidationException::class);

it('skips validation when rules are empty', function () {
    $order = WorkOrder::create(['type' => 't', 'state' => OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::SUBMITTED, 'input' => []]);

    $policy = new EmptyRulesPolicy();
    $result = ['anything' => 'goes'];

    // Should not throw
    $policy->validateSubmission($item, $result);

    expect(true)->toBeTrue();
});

it('readyForApproval returns true when all items are submitted or accepted', function () {
    $order = WorkOrder::create(['type' => 't', 'state' => OrderState::QUEUED, 'payload' => []]);
    WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::SUBMITTED, 'input' => []]);
    WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::ACCEPTED, 'input' => []]);
    WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::SUBMITTED, 'input' => []]);

    $policy = new TestAcceptancePolicy();

    expect($policy->readyForApproval($order))->toBeTrue();
});

it('readyForApproval returns false when any item is not in valid state', function () {
    $order = WorkOrder::create(['type' => 't', 'state' => OrderState::QUEUED, 'payload' => []]);
    WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::SUBMITTED, 'input' => []]);
    WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::IN_PROGRESS, 'input' => []]); // Invalid state
    WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::ACCEPTED, 'input' => []]);

    $policy = new TestAcceptancePolicy();

    expect($policy->readyForApproval($order))->toBeFalse();
});

it('readyForApproval returns false when items are in queued state', function () {
    $order = WorkOrder::create(['type' => 't', 'state' => OrderState::QUEUED, 'payload' => []]);
    WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::QUEUED, 'input' => []]);

    $policy = new TestAcceptancePolicy();

    expect($policy->readyForApproval($order))->toBeFalse();
});

it('readyForApproval respects customApprovalCheck result', function () {
    $order = WorkOrder::create(['type' => 't', 'state' => OrderState::QUEUED, 'payload' => []]);
    WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::SUBMITTED, 'input' => []]);
    WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::ACCEPTED, 'input' => []]);

    $policy = new TestAcceptancePolicy();
    $policy->customApprovalResult = false; // Custom check vetoes approval

    expect($policy->readyForApproval($order))->toBeFalse();
});

it('readyForApproval returns true when customApprovalCheck allows it', function () {
    $order = WorkOrder::create(['type' => 't', 'state' => OrderState::QUEUED, 'payload' => []]);
    WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::SUBMITTED, 'input' => []]);

    $policy = new TestAcceptancePolicy();
    $policy->customApprovalResult = true;

    expect($policy->readyForApproval($order))->toBeTrue();
});

it('readyForApproval returns true for order with no items but custom check passes', function () {
    $order = WorkOrder::create(['type' => 't', 'state' => OrderState::QUEUED, 'payload' => []]);
    // No items created

    $policy = new TestAcceptancePolicy();

    // With no items, the count check (0 === 0) passes
    expect($policy->readyForApproval($order))->toBeTrue();
});

it('fail helper throws ValidationException with structured message', function () {
    $order = WorkOrder::create(['type' => 't', 'state' => OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::SUBMITTED, 'input' => []]);

    $policy = new TestAcceptancePolicy();
    $policy->shouldCallFail = true; // Trigger fail() inside customValidation

    $result = ['status' => 'success', 'count' => 5];

    try {
        $policy->validateSubmission($item, $result);
        expect(false)->toBeTrue(); // Should not reach here
    } catch (ValidationException $e) {
        $errors = $e->errors();
        expect($errors)->toHaveKey('data')
            ->and($errors['data'][0])->toBe('INVALID_FORMAT: Data format is incorrect');
    }
});

it('validates with multiple validation rules', function () {
    $order = WorkOrder::create(['type' => 't', 'state' => OrderState::QUEUED, 'payload' => []]);
    $item = WorkItem::create(['order_id' => $order->id, 'type' => 't', 'state' => ItemState::SUBMITTED, 'input' => []]);

    $policy = new TestAcceptancePolicy();

    // Test multiple invalid fields
    $result = ['status' => 'invalid_status', 'count' => -1]; // Both invalid

    try {
        $policy->validateSubmission($item, $result);
        expect(false)->toBeTrue(); // Should not reach here
    } catch (ValidationException $e) {
        $errors = $e->errors();
        expect($errors)->toHaveKeys(['status', 'count']);
    }
});
