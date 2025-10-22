<?php

namespace GregPriday\WorkManager\Console;

use Illuminate\Console\Command;
use PhpMcp\Laravel\Facades\Mcp;

class McpCommand extends Command
{
    protected $signature = 'work-manager:mcp
                          {--transport=stdio : Transport type (stdio or http)}
                          {--host=127.0.0.1 : Host to bind to (HTTP transport only)}
                          {--port=8090 : Port to listen on (HTTP transport only)}';

    protected $description = 'Start the Work Manager MCP server';

    public function handle(): int
    {
        $transport = $this->option('transport');

        $this->info('Starting Work Manager MCP Server...');
        $this->line('');

        if ($transport === 'stdio') {
            return $this->runStdioServer();
        } elseif ($transport === 'http') {
            return $this->runHttpServer();
        } else {
            $this->error("Invalid transport: {$transport}. Use 'stdio' or 'http'.");

            return self::FAILURE;
        }
    }

    protected function runStdioServer(): int
    {
        $this->info('Transport: STDIO');
        $this->line('Server Name: Laravel Work Manager');
        $this->line('Version: 1.0.0');
        $this->line('');
        $this->comment('The server is now listening on STDIN/STDOUT.');
        $this->comment('Connect your MCP client to this process.');
        $this->line('');
        $this->warn('⚠️  Do not write to stdout in your handlers when using stdio transport!');
        $this->line('');

        try {
            // Use the Laravel MCP package to serve via stdio
            return $this->call('mcp:serve', [
                '--transport' => 'stdio',
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to start stdio server: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    protected function runHttpServer(): int
    {
        $host = $this->option('host');
        $port = $this->option('port');

        $this->info('Transport: HTTP (Dedicated Server)');
        $this->line("Host: {$host}");
        $this->line("Port: {$port}");
        $this->line('Server Name: Laravel Work Manager');
        $this->line('Version: 1.0.0');
        $this->line('');
        $this->comment("MCP server is starting at http://{$host}:{$port}");
        $this->line('');
        $this->info('Available endpoints:');
        $this->line("  - GET  http://{$host}:{$port}/mcp/sse");
        $this->line("  - POST http://{$host}:{$port}/mcp/message");
        $this->line('');
        $this->comment('Press Ctrl+C to stop the server');
        $this->line('');

        try {
            // Use the Laravel MCP package to serve via HTTP
            return $this->call('mcp:serve', [
                '--transport' => 'http',
                '--host' => $host,
                '--port' => $port,
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to start HTTP server: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
