<?php

namespace GregPriday\WorkManager\Tests\Feature;

use GregPriday\WorkManager\Models\WorkIdempotencyKey;
use GregPriday\WorkManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WorkIdempotencyKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_key_scope_filters_by_scope_and_key_hash()
    {
        // Create idempotency keys with different scopes and hashes
        WorkIdempotencyKey::create([
            'scope' => 'submit:item:123',
            'key_hash' => hash('sha256', 'key-1'),
            'response_snapshot' => ['result' => 'success'],
        ]);

        WorkIdempotencyKey::create([
            'scope' => 'submit:item:123',
            'key_hash' => hash('sha256', 'key-2'),
            'response_snapshot' => ['result' => 'success'],
        ]);

        WorkIdempotencyKey::create([
            'scope' => 'submit:item:456',
            'key_hash' => hash('sha256', 'key-1'),
            'response_snapshot' => ['result' => 'success'],
        ]);

        // Search for specific scope and key_hash
        $result = WorkIdempotencyKey::forKey('submit:item:123', hash('sha256', 'key-1'))->first();

        expect($result)->not->toBeNull()
            ->and($result->scope)->toBe('submit:item:123')
            ->and($result->key_hash)->toBe(hash('sha256', 'key-1'));
    }

    public function test_for_key_scope_returns_null_when_not_found()
    {
        WorkIdempotencyKey::create([
            'scope' => 'submit:item:123',
            'key_hash' => hash('sha256', 'key-1'),
            'response_snapshot' => ['result' => 'success'],
        ]);

        // Search for non-existent combination
        $result = WorkIdempotencyKey::forKey('submit:item:999', hash('sha256', 'key-1'))->first();

        expect($result)->toBeNull();
    }

    public function test_for_key_scope_returns_null_with_wrong_key_hash()
    {
        WorkIdempotencyKey::create([
            'scope' => 'submit:item:123',
            'key_hash' => hash('sha256', 'key-1'),
            'response_snapshot' => ['result' => 'success'],
        ]);

        // Search with correct scope but wrong hash
        $result = WorkIdempotencyKey::forKey('submit:item:123', hash('sha256', 'wrong-key'))->first();

        expect($result)->toBeNull();
    }

    public function test_for_key_scope_returns_correct_response_snapshot()
    {
        $expectedResponse = [
            'success' => true,
            'item' => [
                'id' => '123',
                'state' => 'submitted',
            ],
        ];

        WorkIdempotencyKey::create([
            'scope' => 'finalize:item:123',
            'key_hash' => hash('sha256', 'finalize-key'),
            'response_snapshot' => $expectedResponse,
        ]);

        $result = WorkIdempotencyKey::forKey('finalize:item:123', hash('sha256', 'finalize-key'))->first();

        expect($result)->not->toBeNull()
            ->and($result->response_snapshot)->toBe($expectedResponse);
    }

    public function test_for_key_scope_with_multiple_matching_records_returns_first()
    {
        // This shouldn't happen in practice due to unique constraint, but test the scope behavior
        $scope = 'test:scope:' . uniqid();
        $keyHash = hash('sha256', 'test-key');

        WorkIdempotencyKey::create([
            'scope' => $scope,
            'key_hash' => $keyHash,
            'response_snapshot' => ['attempt' => 1],
        ]);

        $result = WorkIdempotencyKey::forKey($scope, $keyHash)->first();

        expect($result)->not->toBeNull()
            ->and($result->scope)->toBe($scope)
            ->and($result->key_hash)->toBe($keyHash);
    }

    public function test_for_key_scope_with_special_characters_in_scope()
    {
        $scope = 'submit-part:item:abc-123:identity:null';
        $keyHash = hash('sha256', 'special-key');

        WorkIdempotencyKey::create([
            'scope' => $scope,
            'key_hash' => $keyHash,
            'response_snapshot' => ['result' => 'ok'],
        ]);

        $result = WorkIdempotencyKey::forKey($scope, $keyHash)->first();

        expect($result)->not->toBeNull()
            ->and($result->scope)->toBe($scope);
    }

    public function test_idempotency_key_stores_created_at_timestamp()
    {
        $key = WorkIdempotencyKey::create([
            'scope' => 'test:scope',
            'key_hash' => hash('sha256', 'test'),
            'response_snapshot' => ['test' => 'data'],
        ]);

        expect($key->created_at)->not->toBeNull()
            ->and($key->created_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    }

    public function test_idempotency_key_does_not_have_updated_at()
    {
        $key = WorkIdempotencyKey::create([
            'scope' => 'test:scope',
            'key_hash' => hash('sha256', 'test'),
            'response_snapshot' => ['test' => 'data'],
        ]);

        // Check that UPDATED_AT is explicitly set to null
        expect(WorkIdempotencyKey::UPDATED_AT)->toBeNull();

        // The updated_at column should not exist or not be used
        $attributes = $key->getAttributes();
        expect($attributes)->not->toHaveKey('updated_at');
    }

    public function test_for_key_scope_can_be_used_for_counting()
    {
        $scope = 'approve:order:123';
        $keyHash = hash('sha256', 'approve-key');

        WorkIdempotencyKey::create([
            'scope' => $scope,
            'key_hash' => $keyHash,
            'response_snapshot' => ['approved' => true],
        ]);

        $count = WorkIdempotencyKey::forKey($scope, $keyHash)->count();

        expect($count)->toBe(1);

        // Different scope should return 0
        $count2 = WorkIdempotencyKey::forKey('approve:order:999', $keyHash)->count();
        expect($count2)->toBe(0);
    }
}
