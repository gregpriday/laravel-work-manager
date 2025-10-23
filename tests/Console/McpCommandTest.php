<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('mcp command rejects invalid transport', function () {
    $this->artisan('work-manager:mcp', ['--transport' => 'websocket'])
        ->expectsOutput('Starting Work Manager MCP Server...')
        ->expectsOutput("Invalid transport: websocket. Use 'stdio' or 'http'.")
        ->assertExitCode(1);
});

test('mcp command rejects invalid transport with mixed case', function () {
    $this->artisan('work-manager:mcp', ['--transport' => 'HTTP'])
        ->expectsOutput("Invalid transport: HTTP. Use 'stdio' or 'http'.")
        ->assertExitCode(1);
});

test('mcp command rejects invalid transport grpc', function () {
    $this->artisan('work-manager:mcp', ['--transport' => 'grpc'])
        ->expectsOutput('Starting Work Manager MCP Server...')
        ->expectsOutput("Invalid transport: grpc. Use 'stdio' or 'http'.")
        ->assertExitCode(1);
});

test('mcp command stdio shows correct output before attempting to start', function () {
    // This test only verifies the output before the actual mcp:serve call
    // We skip the test because we can't mock Artisan in Orchestra Testbench
    if (!class_exists(\PhpMcp\Laravel\Facades\Mcp::class)) {
        $this->markTestSkipped('PhpMcp package not installed');
    }

    // The command will attempt to call mcp:serve which will fail, but we're testing
    // that the correct output is displayed before that point
    $this->artisan('work-manager:mcp', ['--transport' => 'stdio'])
        ->expectsOutput('Starting Work Manager MCP Server...')
        ->expectsOutput('Transport: STDIO')
        ->expectsOutput('Server Name: Laravel Work Manager')
        ->expectsOutput('Version: 1.0.0')
        ->expectsOutput('The server is now listening on STDIN/STDOUT.')
        ->expectsOutput('Connect your MCP client to this process.')
        ->expectsOutputToContain('⚠️')
        ->expectsOutputToContain('Do not write to stdout in your handlers when using stdio transport!');
})->skip('Cannot fully test without mocking Artisan which is final in Orchestra Testbench');

test('mcp command http shows correct output before attempting to start', function () {
    if (!class_exists(\PhpMcp\Laravel\Facades\Mcp::class)) {
        $this->markTestSkipped('PhpMcp package not installed');
    }

    $this->artisan('work-manager:mcp', ['--transport' => 'http'])
        ->expectsOutput('Starting Work Manager MCP Server...')
        ->expectsOutput('Transport: HTTP (ReactPHP Dedicated Server)')
        ->expectsOutput('Host: 127.0.0.1')
        ->expectsOutput('Port: 8090')
        ->expectsOutput('Server Name: Laravel Work Manager')
        ->expectsOutput('Version: 1.0.0')
        ->expectsOutputToContain('MCP server is starting at http://127.0.0.1:8090')
        ->expectsOutput('Available endpoints:')
        ->expectsOutputToContain('GET  http://127.0.0.1:8090/mcp/sse')
        ->expectsOutputToContain('POST http://127.0.0.1:8090/mcp/message')
        ->expectsOutput('Press Ctrl+C to stop the server');
})->skip('Cannot fully test without mocking Artisan which is final in Orchestra Testbench');

test('mcp command http respects custom host and port in output', function () {
    if (!class_exists(\PhpMcp\Laravel\Facades\Mcp::class)) {
        $this->markTestSkipped('PhpMcp package not installed');
    }

    $this->artisan('work-manager:mcp', [
        '--transport' => 'http',
        '--host' => '0.0.0.0',
        '--port' => 9000,
    ])
        ->expectsOutput('Host: 0.0.0.0')
        ->expectsOutput('Port: 9000')
        ->expectsOutputToContain('http://0.0.0.0:9000');
})->skip('Cannot fully test without mocking Artisan which is final in Orchestra Testbench');

test('mcp command http shows auth disabled by default', function () {
    if (!class_exists(\PhpMcp\Laravel\Facades\Mcp::class)) {
        $this->markTestSkipped('PhpMcp package not installed');
    }

    config()->set('work-manager.mcp.http.auth_enabled', false);

    $this->artisan('work-manager:mcp', ['--transport' => 'http'])
        ->expectsOutputToContain('Authentication: DISABLED (public access)');
})->skip('Cannot fully test without mocking Artisan which is final in Orchestra Testbench');

test('mcp command http shows auth enabled when configured', function () {
    if (!class_exists(\PhpMcp\Laravel\Facades\Mcp::class)) {
        $this->markTestSkipped('PhpMcp package not installed');
    }

    config()->set('work-manager.mcp.http.auth_enabled', true);
    config()->set('work-manager.mcp.http.auth_guard', 'sanctum');

    $this->artisan('work-manager:mcp', ['--transport' => 'http'])
        ->expectsOutputToContain('Authentication: ENABLED (Bearer token required)')
        ->expectsOutputToContain('Auth Guard: sanctum');
})->skip('Cannot fully test without mocking Artisan which is final in Orchestra Testbench');

test('mcp command http shows static token count when configured', function () {
    if (!class_exists(\PhpMcp\Laravel\Facades\Mcp::class)) {
        $this->markTestSkipped('PhpMcp package not installed');
    }

    config()->set('work-manager.mcp.http.auth_enabled', true);
    config()->set('work-manager.mcp.http.static_tokens', ['token1', 'token2', 'token3']);

    $this->artisan('work-manager:mcp', ['--transport' => 'http'])
        ->expectsOutputToContain('Static Tokens: 3 configured');
})->skip('Cannot fully test without mocking Artisan which is final in Orchestra Testbench');
