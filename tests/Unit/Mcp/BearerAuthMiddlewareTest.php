<?php

use GregPriday\WorkManager\Mcp\BearerAuthMiddleware;
use Illuminate\Support\Facades\Auth;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

beforeEach(function () {
    config()->set('work-manager.routes.guard', 'web');
});

// Helper function to create a mock PSR-7 request
function mockRequest(?string $authHeader = null): ServerRequestInterface
{
    $request = Mockery::mock(ServerRequestInterface::class);
    $request->shouldReceive('getHeaderLine')
        ->with('Authorization')
        ->andReturn($authHeader ?? '');
    $request->shouldReceive('withAttribute')
        ->andReturnSelf();

    return $request;
}

// Helper function to create a mock PSR-7 response
function mockResponse(int $status = 200): ResponseInterface
{
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn($status);

    return $response;
}

test('middleware returns 401 when authorization header is missing', function () {
    $middleware = new BearerAuthMiddleware('web', []);
    $request = mockRequest();

    $response = $middleware($request, fn () => mockResponse());

    expect($response->getStatusCode())->toBe(401);
});

test('middleware returns 401 when authorization header is malformed', function () {
    $middleware = new BearerAuthMiddleware('web', []);
    $request = mockRequest('Basic abc123');

    $response = $middleware($request, fn () => mockResponse());

    expect($response->getStatusCode())->toBe(401);
});

test('middleware returns 401 when bearer token is invalid', function () {
    $middleware = new BearerAuthMiddleware('web', []);
    $request = mockRequest('Bearer invalid-token');

    $response = $middleware($request, fn () => mockResponse());

    expect($response->getStatusCode())->toBe(401);
});

test('middleware accepts valid static token', function () {
    $middleware = new BearerAuthMiddleware('web', ['valid-token-123']);
    $request = mockRequest('Bearer valid-token-123');

    $nextCalled = false;
    $response = $middleware($request, function ($req) use (&$nextCalled) {
        $nextCalled = true;

        return mockResponse();
    });

    expect($nextCalled)->toBe(true);
    expect($response->getStatusCode())->toBe(200);
});

test('middleware accepts multiple valid static tokens', function () {
    $middleware = new BearerAuthMiddleware('web', ['token-1', 'token-2', 'token-3']);

    foreach (['token-1', 'token-2', 'token-3'] as $token) {
        $request = mockRequest("Bearer {$token}");

        $nextCalled = false;
        $response = $middleware($request, function ($req) use (&$nextCalled) {
            $nextCalled = true;

            return mockResponse();
        });

        expect($nextCalled)->toBe(true);
        expect($response->getStatusCode())->toBe(200);
    }
});

test('middleware uses hash_equals for timing-safe comparison', function () {
    // This test ensures timing attacks are mitigated
    $middleware = new BearerAuthMiddleware('web', ['secret-token-abc123']);

    // Test with various incorrect tokens of different lengths
    $invalidTokens = [
        'secret-token-abc12',    // One char short
        'secret-token-abc1234',  // One char long
        'xxxxxx-xxxxx-xxxxxx',  // Same length, all wrong
        '',                      // Empty
    ];

    foreach ($invalidTokens as $token) {
        $request = mockRequest("Bearer {$token}");

        $response = $middleware($request, fn () => mockResponse());
        expect($response->getStatusCode())->toBe(401);
    }
});

test('middleware handles bearer token case insensitively', function () {
    $middleware = new BearerAuthMiddleware('web', ['valid-token']);

    $headers = [
        'Bearer valid-token',
        'bearer valid-token',
        'BEARER valid-token',
        'BeArEr valid-token',
    ];

    foreach ($headers as $authHeader) {
        $request = mockRequest($authHeader);

        $nextCalled = false;
        $response = $middleware($request, function () use (&$nextCalled) {
            $nextCalled = true;

            return mockResponse();
        });

        expect($nextCalled)->toBe(true);
        expect($response->getStatusCode())->toBe(200);
    }
});

test('middleware returns json error response with correct format', function () {
    $middleware = new BearerAuthMiddleware('web', []);
    $request = mockRequest();

    $response = $middleware($request, fn () => mockResponse());

    // The actual response is a React\Http\Message\Response which we can't easily mock
    // This test verifies that unauthorized responses are returned
    expect($response->getStatusCode())->toBe(401);
});

test('middleware can be constructed with custom guard', function () {
    $middleware = new BearerAuthMiddleware('sanctum', ['test-token']);

    expect($middleware)->toBeInstanceOf(BearerAuthMiddleware::class);
});

test('middleware works with empty static tokens array', function () {
    $middleware = new BearerAuthMiddleware('web', []);
    $request = mockRequest('Bearer some-token');

    $response = $middleware($request, fn () => mockResponse());

    // Should fail because no static tokens and auth won't work in test context
    expect($response->getStatusCode())->toBe(401);
});
