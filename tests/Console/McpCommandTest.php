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
        ->expectsOutputToContain('Do not write to stdout in your handlers when using stdio transport!')
        ->assertExitCode(1); // Expect failure when trying to actually start (no real server)
});

// Note: HTTP transport tests are skipped because they would attempt to bind to actual ports
// which could cause conflicts in CI/CD environments and would hang the test suite.
// The stdio test above verifies the output logic works correctly.
test('mcp command http shows correct output before attempting to start', function () {
    if (!class_exists(\PhpMcp\Laravel\Facades\Mcp::class)) {
        $this->markTestSkipped('PhpMcp package not installed');
    }

    $this->markTestSkipped('HTTP transport would bind to ports - tested manually');
})->skip('HTTP server would bind to actual ports and block');

test('mcp command http respects custom host and port in output', function () {
    if (!class_exists(\PhpMcp\Laravel\Facades\Mcp::class)) {
        $this->markTestSkipped('PhpMcp package not installed');
    }

    $this->markTestSkipped('HTTP transport would bind to ports - tested manually');
})->skip('HTTP server would bind to actual ports and block');

test('mcp command http shows auth disabled by default', function () {
    if (!class_exists(\PhpMcp\Laravel\Facades\Mcp::class)) {
        $this->markTestSkipped('PhpMcp package not installed');
    }

    $this->markTestSkipped('HTTP transport would bind to ports - tested manually');
})->skip('HTTP server would bind to actual ports and block');

test('mcp command http shows auth enabled when configured', function () {
    if (!class_exists(\PhpMcp\Laravel\Facades\Mcp::class)) {
        $this->markTestSkipped('PhpMcp package not installed');
    }

    $this->markTestSkipped('HTTP transport would bind to ports - tested manually');
})->skip('HTTP server would bind to actual ports and block');

test('mcp command http shows static token count when configured', function () {
    if (!class_exists(\PhpMcp\Laravel\Facades\Mcp::class)) {
        $this->markTestSkipped('PhpMcp package not installed');
    }

    $this->markTestSkipped('HTTP transport would bind to ports - tested manually');
})->skip('HTTP server would bind to actual ports and block');
