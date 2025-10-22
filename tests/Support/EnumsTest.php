<?php

use GregPriday\WorkManager\Support\ItemState;
use GregPriday\WorkManager\Support\OrderState;

it('order state identifies terminal states correctly', function () {
    expect(OrderState::COMPLETED->isTerminal())->toBeTrue();
    expect(OrderState::DEAD_LETTERED->isTerminal())->toBeTrue();

    expect(OrderState::QUEUED->isTerminal())->toBeFalse();
    expect(OrderState::IN_PROGRESS->isTerminal())->toBeFalse();
    expect(OrderState::APPROVED->isTerminal())->toBeFalse();
    expect(OrderState::APPLIED->isTerminal())->toBeFalse();
});

it('item state identifies terminal states correctly', function () {
    expect(ItemState::COMPLETED->isTerminal())->toBeTrue();
    expect(ItemState::REJECTED->isTerminal())->toBeTrue();
    expect(ItemState::DEAD_LETTERED->isTerminal())->toBeTrue();

    expect(ItemState::QUEUED->isTerminal())->toBeFalse();
    expect(ItemState::LEASED->isTerminal())->toBeFalse();
    expect(ItemState::SUBMITTED->isTerminal())->toBeFalse();
});

it('order state allows legal transitions per config', function () {
    // These transitions should be legal based on config/work-manager.php
    expect(OrderState::QUEUED->canTransitionTo(OrderState::CHECKED_OUT))->toBeTrue();
    expect(OrderState::CHECKED_OUT->canTransitionTo(OrderState::IN_PROGRESS))->toBeTrue();
    expect(OrderState::IN_PROGRESS->canTransitionTo(OrderState::SUBMITTED))->toBeTrue();
    expect(OrderState::SUBMITTED->canTransitionTo(OrderState::APPROVED))->toBeTrue();
    expect(OrderState::APPROVED->canTransitionTo(OrderState::APPLIED))->toBeTrue();
});

it('order state blocks illegal transitions', function () {
    // These transitions should be illegal based on config
    expect(OrderState::QUEUED->canTransitionTo(OrderState::APPROVED))->toBeFalse();
    expect(OrderState::QUEUED->canTransitionTo(OrderState::APPLIED))->toBeFalse();
    expect(OrderState::QUEUED->canTransitionTo(OrderState::COMPLETED))->toBeFalse();
    expect(OrderState::COMPLETED->canTransitionTo(OrderState::QUEUED))->toBeFalse();
    expect(OrderState::SUBMITTED->canTransitionTo(OrderState::COMPLETED))->toBeFalse();
});

it('item state allows legal transitions per config', function () {
    // These transitions should be legal based on config/work-manager.php
    expect(ItemState::QUEUED->canTransitionTo(ItemState::LEASED))->toBeTrue();
    expect(ItemState::LEASED->canTransitionTo(ItemState::IN_PROGRESS))->toBeTrue();
    expect(ItemState::IN_PROGRESS->canTransitionTo(ItemState::SUBMITTED))->toBeTrue();
    expect(ItemState::SUBMITTED->canTransitionTo(ItemState::ACCEPTED))->toBeTrue();
});

it('item state blocks illegal transitions', function () {
    // These transitions should be illegal
    expect(ItemState::QUEUED->canTransitionTo(ItemState::SUBMITTED))->toBeFalse();
    expect(ItemState::QUEUED->canTransitionTo(ItemState::ACCEPTED))->toBeFalse();
    expect(ItemState::COMPLETED->canTransitionTo(ItemState::QUEUED))->toBeFalse();
});

it('all order states have string values', function () {
    expect(OrderState::QUEUED->value)->toBe('queued');
    expect(OrderState::CHECKED_OUT->value)->toBe('checked_out');
    expect(OrderState::IN_PROGRESS->value)->toBe('in_progress');
    expect(OrderState::SUBMITTED->value)->toBe('submitted');
    expect(OrderState::APPROVED->value)->toBe('approved');
    expect(OrderState::APPLIED->value)->toBe('applied');
    expect(OrderState::COMPLETED->value)->toBe('completed');
    expect(OrderState::REJECTED->value)->toBe('rejected');
    expect(OrderState::FAILED->value)->toBe('failed');
    expect(OrderState::DEAD_LETTERED->value)->toBe('dead_lettered');
});

it('all item states have string values', function () {
    expect(ItemState::QUEUED->value)->toBe('queued');
    expect(ItemState::LEASED->value)->toBe('leased');
    expect(ItemState::IN_PROGRESS->value)->toBe('in_progress');
    expect(ItemState::SUBMITTED->value)->toBe('submitted');
    expect(ItemState::ACCEPTED->value)->toBe('accepted');
    expect(ItemState::REJECTED->value)->toBe('rejected');
    expect(ItemState::COMPLETED->value)->toBe('completed');
    expect(ItemState::FAILED->value)->toBe('failed');
    expect(ItemState::DEAD_LETTERED->value)->toBe('dead_lettered');
});
