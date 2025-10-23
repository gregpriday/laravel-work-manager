<?php

use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Policies\WorkOrderPolicy;
use Illuminate\Foundation\Auth\User;

beforeEach(function () {
    $this->policy = new WorkOrderPolicy;
    $this->order = WorkOrder::create([
        'type' => 'test.echo',
        'payload' => ['message' => 'test'],
        'requested_by_type' => 'user',
        'requested_by_id' => '123',
    ]);
});

test('propose allows authenticated user without can method', function () {
    $user = new class extends User
    {
        public $id = 1;
    };

    expect($this->policy->propose($user))->toBeTrue();
});

test('propose denies null user', function () {
    expect($this->policy->propose(null))->toBeFalse();
});

test('propose checks user can method when available', function () {
    config()->set('work-manager.policies.propose', 'work.propose');

    $user = new class extends User
    {
        public $id = 1;

        public function can($abilities, $arguments = [])
        {
            return $abilities === 'work.propose';
        }
    };

    expect($this->policy->propose($user))->toBeTrue();
});

test('propose denies when user cannot based on ability', function () {
    config()->set('work-manager.policies.propose', 'work.propose');

    $user = new class extends User
    {
        public $id = 1;

        public function can($abilities, $arguments = [])
        {
            return false;
        }
    };

    expect($this->policy->propose($user))->toBeFalse();
});

test('view allows requester of the order', function () {
    $user = new class extends User
    {
        public $id = 123;
    };

    expect($this->policy->view($user, $this->order))->toBeTrue();
});

test('view allows any authenticated user', function () {
    $user = new class extends User
    {
        public $id = 999; // Different from requester
    };

    expect($this->policy->view($user, $this->order))->toBeTrue();
});

test('view denies null user', function () {
    expect($this->policy->view(null, $this->order))->toBeFalse();
});

test('checkout allows authenticated user without can method', function () {
    $user = new class extends User
    {
        public $id = 1;
    };

    expect($this->policy->checkout($user, $this->order))->toBeTrue();
});

test('checkout denies null user', function () {
    expect($this->policy->checkout(null, $this->order))->toBeFalse();
});

test('checkout checks user can method when available', function () {
    config()->set('work-manager.policies.checkout', 'work.checkout');

    $user = new class extends User
    {
        public $id = 1;

        public function can($abilities, $arguments = [])
        {
            return $abilities === 'work.checkout';
        }
    };

    expect($this->policy->checkout($user, $this->order))->toBeTrue();
});

test('checkout denies when user cannot based on ability', function () {
    config()->set('work-manager.policies.checkout', 'work.checkout');

    $user = new class extends User
    {
        public $id = 1;

        public function can($abilities, $arguments = [])
        {
            return false;
        }
    };

    expect($this->policy->checkout($user, $this->order))->toBeFalse();
});

test('submit allows authenticated user without can method', function () {
    $user = new class extends User
    {
        public $id = 1;
    };

    expect($this->policy->submit($user, $this->order))->toBeTrue();
});

test('submit denies null user', function () {
    expect($this->policy->submit(null, $this->order))->toBeFalse();
});

test('submit checks user can method when available', function () {
    config()->set('work-manager.policies.submit', 'work.submit');

    $user = new class extends User
    {
        public $id = 1;

        public function can($abilities, $arguments = [])
        {
            return $abilities === 'work.submit';
        }
    };

    expect($this->policy->submit($user, $this->order))->toBeTrue();
});

test('submit denies when user cannot based on ability', function () {
    config()->set('work-manager.policies.submit', 'work.submit');

    $user = new class extends User
    {
        public $id = 1;

        public function can($abilities, $arguments = [])
        {
            return false;
        }
    };

    expect($this->policy->submit($user, $this->order))->toBeFalse();
});

test('approve denies null user', function () {
    expect($this->policy->approve(null, $this->order))->toBeFalse();
});

test('approve allows when user has can method and returns true', function () {
    config()->set('work-manager.policies.approve', 'work.approve');

    $user = new class extends User
    {
        public $id = 1;

        public function can($abilities, $arguments = [])
        {
            return $abilities === 'work.approve';
        }
    };

    expect($this->policy->approve($user, $this->order))->toBeTrue();
});

test('approve denies when user has can method but returns false', function () {
    config()->set('work-manager.policies.approve', 'work.approve');

    $user = new class extends User
    {
        public $id = 1;

        public function can($abilities, $arguments = [])
        {
            return false;
        }
    };

    expect($this->policy->approve($user, $this->order))->toBeFalse();
});

test('reject denies null user', function () {
    expect($this->policy->reject(null, $this->order))->toBeFalse();
});

test('reject allows when user has can method and returns true', function () {
    config()->set('work-manager.policies.reject', 'work.reject');

    $user = new class extends User
    {
        public $id = 1;

        public function can($abilities, $arguments = [])
        {
            return $abilities === 'work.reject';
        }
    };

    expect($this->policy->reject($user, $this->order))->toBeTrue();
});

test('reject denies when user has can method but returns false', function () {
    config()->set('work-manager.policies.reject', 'work.reject');

    $user = new class extends User
    {
        public $id = 1;

        public function can($abilities, $arguments = [])
        {
            return false;
        }
    };

    expect($this->policy->reject($user, $this->order))->toBeFalse();
});

test('view matches requester ID with string comparison', function () {
    // Test with numeric user ID
    $user = new class extends User
    {
        public $id = 123; // Integer
    };

    // Order has requested_by_id as string '123'
    expect($this->policy->view($user, $this->order))->toBeTrue();
});

test('view does not match when requester ID different', function () {
    $user = new class extends User
    {
        public $id = 456;
    };

    // Still returns true because any authenticated user can view
    expect($this->policy->view($user, $this->order))->toBeTrue();
});
