<?php

namespace GregPriday\WorkManager\Console;

use GregPriday\WorkManager\Mcp\BearerAuthMiddleware;
use Illuminate\Console\Command;
use PhpMcp\Laravel\Facades\Mcp;
use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\ReactPhpHttpTransportHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\Promise;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;

/**
 * Starts MCP server in STDIO (local AI IDEs) or HTTP (remote agents) mode.
 *
 * @internal Exposes WorkManagerTools via php-mcp.
 *
 * @see docs/guides/mcp-server-integration.md
 */
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
            $this->error('Failed to start stdio server: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    protected function runHttpServer(): int
    {
        $host = $this->option('host');
        $port = $this->option('port');

        // Check if auth is enabled
        $authEnabled = config('work-manager.mcp.http.auth_enabled', false);

        $this->info('Transport: HTTP (ReactPHP Dedicated Server)');
        $this->line("Host: {$host}");
        $this->line("Port: {$port}");
        $this->line('Server Name: Laravel Work Manager');
        $this->line('Version: 1.0.0');
        $this->line('');

        if ($authEnabled) {
            $this->warn('Authentication: ENABLED (Bearer token required)');
            $guard = config('work-manager.mcp.http.auth_guard', 'sanctum');
            $this->line("Auth Guard: {$guard}");
            $staticTokens = config('work-manager.mcp.http.static_tokens', []);
            if (! empty($staticTokens)) {
                $this->line('Static Tokens: '.count($staticTokens).' configured');
            }
        } else {
            $this->comment('Authentication: DISABLED (public access)');
        }

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
            // Create MCP server
            $server = app(Server::class);
            $logger = app(LoggerInterface::class);
            $transportHandler = new ReactPhpHttpTransportHandler($server);

            // Build middleware chain
            $middlewares = [];

            // Add CORS middleware if enabled
            if (config('work-manager.mcp.http.cors.enabled', true)) {
                $middlewares[] = $this->createCorsMiddleware();
            }

            // Add auth middleware if enabled
            if ($authEnabled) {
                $guard = config('work-manager.mcp.http.auth_guard', 'sanctum');
                $staticTokens = config('work-manager.mcp.http.static_tokens', []);
                $middlewares[] = new BearerAuthMiddleware($guard, $staticTokens);
            }

            // Create HTTP server with request handler
            $http = new HttpServer(
                $this->createRequestHandler($transportHandler, $logger, $middlewares)
            );

            // Create socket and start listening
            $listenAddress = "{$host}:{$port}";
            $socket = new SocketServer($listenAddress);

            $logger->info("ReactPHP MCP Server listening on {$listenAddress}");

            // Start the server (blocking call)
            $http->listen($socket);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to start HTTP server: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }
    }

    /**
     * Create the HTTP request handler with middleware chain.
     */
    protected function createRequestHandler(
        ReactPhpHttpTransportHandler $transportHandler,
        LoggerInterface $logger,
        array $middlewares = []
    ): callable {
        $postEndpoint = '/mcp/message';
        $sseEndpoint = '/mcp/sse';

        return function (ServerRequestInterface $request) use (
            $logger,
            $transportHandler,
            $postEndpoint,
            $sseEndpoint,
            $middlewares
        ): ResponseInterface|Promise {
            $path = $request->getUri()->getPath();
            $method = $request->getMethod();

            $logger->info('Received request', ['method' => $method, 'path' => $path]);

            // Handle OPTIONS preflight for CORS
            if ($method === 'OPTIONS') {
                return $this->handleCorsPreFlight($request);
            }

            // Apply middleware chain for authenticated endpoints
            if (! empty($middlewares)) {
                foreach ($middlewares as $middleware) {
                    $result = $middleware($request, function ($req) use (&$request) {
                        $request = $req;

                        // Return a pass-through response (will be replaced by actual handler)
                        return new Response(200);
                    });

                    // If middleware returns a non-200 response, short-circuit
                    if ($result instanceof ResponseInterface && $result->getStatusCode() !== 200) {
                        return $result;
                    }
                }
            }

            // POST Endpoint Handling
            if ($method === 'POST' && str_starts_with($path, $postEndpoint)) {
                return $this->handlePostMessage($request, $transportHandler, $logger);
            }

            // SSE Endpoint Handling
            if ($method === 'GET' && $path === $sseEndpoint) {
                return $this->handleSseConnection($request, $transportHandler, $logger, $postEndpoint);
            }

            // Fallback 404
            return new Response(404, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32000, 'message' => 'Not Found'],
            ]));
        };
    }

    /**
     * Handle POST /mcp/message requests.
     */
    protected function handlePostMessage(
        ServerRequestInterface $request,
        ReactPhpHttpTransportHandler $transportHandler,
        LoggerInterface $logger
    ): ResponseInterface {
        $queryParams = $request->getQueryParams();
        $clientId = $queryParams['clientId'] ?? null;

        if (! $clientId || ! is_string($clientId)) {
            return new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32600, 'message' => 'Missing or invalid clientId query parameter'],
            ]));
        }

        if (! str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            return new Response(415, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32600, 'message' => 'Content-Type must be application/json'],
            ]));
        }

        $requestBody = (string) $request->getBody();
        if (empty($requestBody)) {
            return new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32600, 'message' => 'Empty request body'],
            ]));
        }

        try {
            $transportHandler->handleInput($requestBody, $clientId);

            return new Response(202, ['Content-Type' => 'application/json']); // Accepted
        } catch (\JsonException $e) {
            return new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32700, 'message' => "Invalid JSON - {$e->getMessage()}"],
            ]));
        } catch (\Throwable $e) {
            $logger->error('Error handling POST message', ['error' => $e->getMessage()]);

            return new Response(500, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32000, 'message' => 'Internal Server Error'],
            ]));
        }
    }

    /**
     * Handle GET /mcp/sse requests.
     */
    protected function handleSseConnection(
        ServerRequestInterface $request,
        ReactPhpHttpTransportHandler $transportHandler,
        LoggerInterface $logger,
        string $postEndpoint
    ): ResponseInterface {
        $clientId = 'client_'.bin2hex(random_bytes(16));

        $logger->info('ReactPHP SSE connection opening', ['client_id' => $clientId]);

        $stream = new ThroughStream;

        $postEndpointWithClientId = $postEndpoint.'?clientId='.urlencode($clientId);

        $transportHandler->setClientSseStream($clientId, $stream);
        $transportHandler->handleSseConnection($clientId, $postEndpointWithClientId);

        $sseHeaders = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];

        // Add CORS headers if enabled
        if (config('work-manager.mcp.http.cors.enabled', true)) {
            $sseHeaders['Access-Control-Allow-Origin'] = config('work-manager.mcp.http.cors.allowed_origins', '*');
        }

        return new Response(200, $sseHeaders, $stream);
    }

    /**
     * Handle CORS preflight requests.
     */
    protected function handleCorsPreFlight(ServerRequestInterface $request): ResponseInterface
    {
        $corsConfig = config('work-manager.mcp.http.cors', []);

        return new Response(204, [
            'Access-Control-Allow-Origin' => $corsConfig['allowed_origins'] ?? '*',
            'Access-Control-Allow-Methods' => $corsConfig['allowed_methods'] ?? 'GET,POST,OPTIONS',
            'Access-Control-Allow-Headers' => $corsConfig['allowed_headers'] ?? 'Content-Type,Authorization,Mcp-Session-Id',
            'Access-Control-Max-Age' => '86400',
        ]);
    }

    /**
     * Create CORS middleware.
     */
    protected function createCorsMiddleware(): callable
    {
        $corsConfig = config('work-manager.mcp.http.cors', []);

        return function (ServerRequestInterface $request, callable $next) use ($corsConfig): ResponseInterface {
            $response = $next($request);

            // Add CORS headers to response
            $origin = $corsConfig['allowed_origins'] ?? '*';

            if ($response instanceof ResponseInterface) {
                return $response
                    ->withHeader('Access-Control-Allow-Origin', $origin)
                    ->withHeader('Access-Control-Allow-Methods', $corsConfig['allowed_methods'] ?? 'GET,POST,OPTIONS')
                    ->withHeader('Access-Control-Allow-Headers', $corsConfig['allowed_headers'] ?? 'Content-Type,Authorization,Mcp-Session-Id');
            }

            return $response;
        };
    }
}
