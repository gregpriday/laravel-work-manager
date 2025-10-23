<?php

use GregPriday\WorkManager\Support\PartStatus;

it('PartStatus VALIDATED is terminal', function () {
    expect(PartStatus::VALIDATED->isTerminal())->toBeTrue();
});

it('PartStatus REJECTED is terminal', function () {
    expect(PartStatus::REJECTED->isTerminal())->toBeTrue();
});

it('PartStatus DRAFT is not terminal', function () {
    expect(PartStatus::DRAFT->isTerminal())->toBeFalse();
});

it('PartStatus has correct string values', function () {
    expect(PartStatus::DRAFT->value)->toBe('draft')
        ->and(PartStatus::VALIDATED->value)->toBe('validated')
        ->and(PartStatus::REJECTED->value)->toBe('rejected');
});

it('PartStatus can be instantiated from string', function () {
    expect(PartStatus::from('draft'))->toBe(PartStatus::DRAFT)
        ->and(PartStatus::from('validated'))->toBe(PartStatus::VALIDATED)
        ->and(PartStatus::from('rejected'))->toBe(PartStatus::REJECTED);
});

it('PartStatus tryFrom returns null for invalid value', function () {
    expect(PartStatus::tryFrom('invalid'))->toBeNull();
});

it('PartStatus cases returns all cases', function () {
    $cases = PartStatus::cases();

    expect($cases)->toHaveCount(3)
        ->and($cases)->toContain(PartStatus::DRAFT)
        ->and($cases)->toContain(PartStatus::VALIDATED)
        ->and($cases)->toContain(PartStatus::REJECTED);
});
