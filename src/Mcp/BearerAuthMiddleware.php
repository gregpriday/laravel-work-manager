<?php

namespace GregPriday\WorkManager\Mcp;

use Illuminate\Support\Facades\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response as HttpResponse;

/**
 * PSR-7 middleware for Bearer token authentication on MCP HTTP transport.
 *
 * Validates the Authorization header and integrates with Laravel's
 * authentication system to verify tokens.
 */
class BearerAuthMiddleware
{
    /**
     * Create a new Bearer auth middleware instance.
     *
     * @param  string|null  $guard  The Laravel auth guard to use (e.g., 'sanctum')
     * @param  array<string>  $allowedTokens  Optional array of static tokens to allow
     */
    public function __construct(
        protected ?string $guard = null,
        protected array $allowedTokens = []
    ) {
        $this->guard = $guard ?? config('work-manager.routes.guard', 'sanctum');
    }

    /**
     * Handle an incoming request.
     *
     * @param  callable  $next  Next middleware in the chain
     */
    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        // Extract Authorization header
        $authHeader = $request->getHeaderLine('Authorization');

        // Check for Bearer token
        if (! preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $this->unauthorizedResponse('Missing or invalid Authorization header');
        }

        $token = $matches[1];

        // Validate token
        if (! $this->isValidToken($token)) {
            return $this->unauthorizedResponse('Invalid authentication token');
        }

        // Attach authenticated user/agent to request attributes for downstream use
        $request = $request->withAttribute('authenticated', true);

        if (Auth::check()) {
            $request = $request->withAttribute('user_id', Auth::id());
        }

        // Continue to next middleware
        return $next($request);
    }

    /**
     * Validate the provided token.
     */
    protected function isValidToken(string $token): bool
    {
        // Check static tokens first (for simple setup)
        if (! empty($this->allowedTokens)) {
            foreach ($this->allowedTokens as $allowedToken) {
                if (hash_equals($allowedToken, $token)) {
                    return true;
                }
            }
        }

        // Attempt to authenticate using Laravel's Sanctum or configured guard
        try {
            // For Sanctum, we need to create a request with the Bearer token
            $authRequest = request();
            $authRequest->headers->set('Authorization', 'Bearer ' . $token);

            // Attempt authentication
            $user = Auth::guard($this->guard)->user();

            return $user !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create a 401 Unauthorized response.
     */
    protected function unauthorizedResponse(string $message = 'Unauthorized'): HttpResponse
    {
        return new HttpResponse(
            401,
            [
                'Content-Type' => 'application/json',
                'WWW-Authenticate' => 'Bearer realm="mcp"',
            ],
            json_encode([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32000,
                    'message' => $message,
                ],
            ])
        );
    }
}
