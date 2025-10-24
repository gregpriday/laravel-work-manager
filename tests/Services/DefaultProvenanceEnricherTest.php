<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use GregPriday\WorkManager\Services\Provenance\DefaultProvenanceEnricher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2025-01-02 03:04:05Z'));
});

afterEach(function () {
    Carbon::setTestNow();
    Auth::clearResolvedInstances();
});

/**
 * Helper to create a request with session
 */
function createRequest(string $uri = '/test', string $method = 'GET', array $server = []): Request
{
    $request = Request::create($uri, $method, [], [], [], $server);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

/**
 * enrich() method tests
 */
it('enriches from headers and request context', function () {
    $request = createRequest('/test', 'GET', [
        'HTTP_X_AGENT_ID' => 'agent-123',
        'HTTP_X_AGENT_NAME' => 'my-agent',
        'HTTP_X_AGENT_VERSION' => '1.2.3',
        'HTTP_X_MODEL_NAME' => 'gpt-4',
        'HTTP_X_RUNTIME' => 'php-8.3',
        'HTTP_ACCEPT_LANGUAGE' => 'en-US',
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_USER_AGENT' => 'TestAgent/1.0',
        'HTTP_X_REQUEST_ID' => 'req-abc',
    ]);

    $enricher = new DefaultProvenanceEnricher;
    $result = $enricher->enrich($request, ['extra' => 'ok']);

    expect($result['agent_id'])->toBe('agent-123')
        ->and($result['agent_name'])->toBe('my-agent')
        ->and($result['agent_version'])->toBe('1.2.3')
        ->and($result['model_name'])->toBe('gpt-4')
        ->and($result['runtime'])->toBe('php-8.3')
        ->and($result['ip_address'])->toBe('203.0.113.10')
        ->and($result['user_agent'])->toBe('TestAgent/1.0')
        ->and($result['request_id'])->toBe('req-abc')
        ->and($result['timestamp'])->toStartWith('2025-01-02')
        ->and($result['fingerprint'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($result['extra'])->toBe('ok');
});

it('generates request_id when not provided', function () {
    $request = createRequest('/test', 'GET');

    $enricher = new DefaultProvenanceEnricher;
    $result = $enricher->enrich($request);

    expect($result['request_id'])->not->toBeNull()
        ->and($result['request_id'])->toMatch('/^[a-f0-9\-]{36}$/'); // UUID format
});

it('merges context data', function () {
    $request = createRequest('/test', 'GET');

    $enricher = new DefaultProvenanceEnricher;
    $result = $enricher->enrich($request, [
        'custom_field' => 'custom_value',
        'another' => 123,
    ]);

    expect($result['custom_field'])->toBe('custom_value')
        ->and($result['another'])->toBe(123);
});

it('includes session id when session is available', function () {
    $request = createRequest('/test', 'GET');

    $enricher = new DefaultProvenanceEnricher;
    $result = $enricher->enrich($request);

    expect($result)->toHaveKey('session_id');
});

/**
 * getAgentId() method tests
 */
it('getAgentId returns header value when present', function () {
    $request = createRequest('/test', 'GET', [
        'HTTP_X_AGENT_ID' => 'agent-from-header',
    ]);

    $enricher = new DefaultProvenanceEnricher;
    $result = $enricher->enrich($request);

    expect($result['agent_id'])->toBe('agent-from-header');
});

it('getAgentId supports alternative casing X-Agent-Id', function () {
    $request = createRequest('/test', 'GET', [
        'HTTP_X_AGENT_Id' => 'agent-alt-casing',
    ]);

    $enricher = new DefaultProvenanceEnricher;
    $result = $enricher->enrich($request);

    expect($result['agent_id'])->toBe('agent-alt-casing');
});

it('getAgentId returns null when no header and not authenticated', function () {
    $request = createRequest('/test', 'GET');

    $enricher = new DefaultProvenanceEnricher;
    $result = $enricher->enrich($request);

    expect($result['agent_id'])->toBeNull();
});

/**
 * generateFingerprint() method tests
 */
it('generateFingerprint produces consistent sha256 hash', function () {
    $request = createRequest('/test', 'GET', [
        'HTTP_X_AGENT_ID' => 'agent-123',
        'HTTP_ACCEPT_LANGUAGE' => 'en-US',
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_USER_AGENT' => 'TestAgent/1.0',
    ]);

    $enricher = new DefaultProvenanceEnricher;
    $result1 = $enricher->enrich($request);
    $result2 = $enricher->enrich($request);

    expect($result1['fingerprint'])->toBe($result2['fingerprint'])
        ->and($result1['fingerprint'])->toMatch('/^[a-f0-9]{64}$/');
});

it('generateFingerprint includes Accept-Language header', function () {
    $request1 = createRequest('/test', 'GET', [
        'HTTP_ACCEPT_LANGUAGE' => 'en-US',
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_USER_AGENT' => 'TestAgent/1.0',
    ]);

    $request2 = createRequest('/test', 'GET', [
        'HTTP_ACCEPT_LANGUAGE' => 'fr-FR',
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_USER_AGENT' => 'TestAgent/1.0',
    ]);

    $enricher = new DefaultProvenanceEnricher;
    $result1 = $enricher->enrich($request1);
    $result2 = $enricher->enrich($request2);

    expect($result1['fingerprint'])->not->toBe($result2['fingerprint']);
});

it('generateFingerprint changes with different request attributes', function () {
    $request1 = createRequest('/test', 'GET', [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_USER_AGENT' => 'TestAgent/1.0',
    ]);

    $request2 = createRequest('/test', 'GET', [
        'REMOTE_ADDR' => '203.0.113.20',
        'HTTP_USER_AGENT' => 'TestAgent/1.0',
    ]);

    $enricher = new DefaultProvenanceEnricher;
    $result1 = $enricher->enrich($request1);
    $result2 = $enricher->enrich($request2);

    expect($result1['fingerprint'])->not->toBe($result2['fingerprint']);
});

/**
 * validate() method tests
 */
it('validate returns error when X-Agent-ID is missing', function () {
    $request = createRequest('/test', 'GET');

    $enricher = new DefaultProvenanceEnricher;
    $errors = $enricher->validate($request);

    expect($errors)->toContain('Missing required header: X-Agent-ID');
});

it('validate returns empty array when X-Agent-ID is present', function () {
    $request = createRequest('/test', 'GET', [
        'HTTP_X_AGENT_ID' => 'agent-123',
    ]);

    $enricher = new DefaultProvenanceEnricher;
    $errors = $enricher->validate($request);

    expect($errors)->toBeEmpty();
});

it('validate accepts alternative casing X-Agent-Id', function () {
    $request = createRequest('/test', 'GET', [
        'HTTP_X_AGENT_Id' => 'agent-alt-casing',
    ]);

    $enricher = new DefaultProvenanceEnricher;
    $errors = $enricher->validate($request);

    expect($errors)->toBeEmpty();
});

it('validate returns error for invalid semver', function () {
    $request = createRequest('/test', 'GET', [
        'HTTP_X_AGENT_ID' => 'agent-123',
        'HTTP_X_AGENT_VERSION' => 'not-a-version',
    ]);

    $enricher = new DefaultProvenanceEnricher;
    $errors = $enricher->validate($request);

    expect($errors)->toContain('Invalid semantic version in X-Agent-Version header');
});

it('validate accepts valid semver versions', function () {
    $validVersions = ['1.2.3', 'v1.2.3', '0.0.1', '10.20.30', '1.0.0-alpha', '1.0.0+build.123'];

    foreach ($validVersions as $version) {
        $request = createRequest('/test', 'GET', [
            'HTTP_X_AGENT_ID' => 'agent-123',
            'HTTP_X_AGENT_VERSION' => $version,
        ]);

        $enricher = new DefaultProvenanceEnricher;
        $errors = $enricher->validate($request);

        expect($errors)->toBeEmpty();
    }
});

/**
 * extractAgentType() method tests
 */
it('extractAgentType extracts type from pattern type-instance', function () {
    $enricher = new DefaultProvenanceEnricher;

    expect($enricher->extractAgentType('research-agent-1'))->toBe('research-agent')
        ->and($enricher->extractAgentType('fact-checker-123'))->toBe('fact-checker')
        ->and($enricher->extractAgentType('user-456'))->toBe('user');
});

it('extractAgentType extracts first segment when no instance number', function () {
    $enricher = new DefaultProvenanceEnricher;

    expect($enricher->extractAgentType('researcher'))->toBe('researcher')
        ->and($enricher->extractAgentType('fact-checker'))->toBe('fact-checker');
});

it('extractAgentType returns null for invalid patterns', function () {
    $enricher = new DefaultProvenanceEnricher;

    expect($enricher->extractAgentType('123-invalid'))->toBeNull()
        ->and($enricher->extractAgentType(''))->toBeNull();
});

/**
 * createRecord() method tests
 */
it('createRecord filters null values', function () {
    $enricher = new DefaultProvenanceEnricher;
    $record = $enricher->createRecord([
        'agent_id' => 'agent-123',
        'agent_name' => null,
        'agent_version' => '1.2.3',
        'model_name' => null,
        'runtime' => null,
        'fingerprint' => 'abc123',
        'request_id' => 'req-123',
        'ip_address' => '1.2.3.4',
        'user_agent' => null,
        'authenticated_user_id' => null,
        'session_id' => null,
        'timestamp' => '2025-01-02T03:04:05Z',
    ]);

    expect($record)->toHaveKeys(['agent_id', 'agent_version', 'agent_type', 'request_fingerprint', 'request_id', 'ip_address', 'metadata'])
        ->and($record)->not->toHaveKey('agent_name')
        ->and($record)->not->toHaveKey('model_name')
        ->and($record)->not->toHaveKey('runtime')
        ->and($record)->not->toHaveKey('user_agent');
});

it('createRecord extracts agent_type from agent_id', function () {
    $enricher = new DefaultProvenanceEnricher;
    $record = $enricher->createRecord([
        'agent_id' => 'research-agent-1',
        'fingerprint' => 'abc123',
        'request_id' => 'req-123',
        'timestamp' => '2025-01-02T03:04:05Z',
    ]);

    expect($record['agent_type'])->toBe('research-agent');
});

it('createRecord uses provided agent_type over extracted one', function () {
    $enricher = new DefaultProvenanceEnricher;
    $record = $enricher->createRecord([
        'agent_id' => 'research-agent-1',
        'agent_type' => 'custom-type',
        'fingerprint' => 'abc123',
        'request_id' => 'req-123',
        'timestamp' => '2025-01-02T03:04:05Z',
    ]);

    expect($record['agent_type'])->toBe('custom-type');
});

it('createRecord nests metadata correctly', function () {
    $enricher = new DefaultProvenanceEnricher;
    $record = $enricher->createRecord([
        'agent_id' => 'agent-123',
        'fingerprint' => 'abc123',
        'request_id' => 'req-123',
        'authenticated_user_id' => 456,
        'session_id' => 'sess-789',
        'timestamp' => '2025-01-02T03:04:05Z',
    ]);

    expect($record['metadata'])->toHaveKeys(['authenticated_user_id', 'session_id', 'timestamp'])
        ->and($record['metadata']['authenticated_user_id'])->toBe(456)
        ->and($record['metadata']['session_id'])->toBe('sess-789')
        ->and($record['metadata']['timestamp'])->toBe('2025-01-02T03:04:05Z');
});

it('createRecord filters null values from metadata', function () {
    $enricher = new DefaultProvenanceEnricher;
    $record = $enricher->createRecord([
        'agent_id' => 'agent-123',
        'fingerprint' => 'abc123',
        'request_id' => 'req-123',
        'authenticated_user_id' => null,
        'session_id' => null,
        'timestamp' => '2025-01-02T03:04:05Z',
    ]);

    expect($record['metadata'])->toHaveKey('timestamp')
        ->and($record['metadata'])->not->toHaveKey('authenticated_user_id')
        ->and($record['metadata'])->not->toHaveKey('session_id');
});

it('createRecord creates empty metadata when all values are null', function () {
    $enricher = new DefaultProvenanceEnricher;
    $record = $enricher->createRecord([
        'agent_id' => 'agent-123',
        'fingerprint' => 'abc123',
        'request_id' => 'req-123',
        'authenticated_user_id' => null,
        'session_id' => null,
        'timestamp' => null,
    ]);

    // Empty arrays pass through the filter since [] !== null
    expect($record)->toHaveKey('metadata')
        ->and($record['metadata'])->toBeEmpty();
});

it('createRecord maps fingerprint to request_fingerprint', function () {
    $enricher = new DefaultProvenanceEnricher;
    $record = $enricher->createRecord([
        'agent_id' => 'agent-123',
        'fingerprint' => 'abc123def456',
        'request_id' => 'req-123',
        'timestamp' => '2025-01-02T03:04:05Z',
    ]);

    expect($record['request_fingerprint'])->toBe('abc123def456')
        ->and($record)->not->toHaveKey('fingerprint');
});
