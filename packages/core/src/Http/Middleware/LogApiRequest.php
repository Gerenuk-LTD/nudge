<?php

namespace Gerenuk\Marketing\Http\Middleware;

use Closure;
use Gerenuk\Marketing\Models\ApiRequest;
use Gerenuk\Marketing\Models\ApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records each authenticated API request and its response for the workspace's API log. Runs after the token is
 * authenticated, so requests without a valid token (which belong to no workspace) aren't recorded. A failure to
 * record never fails the request.
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $ulid = strtolower((string) Str::ulid());
        $response = $next($request);

        $response->headers->set('X-Request-Id', 'req_'.$ulid);

        if (config('marketing.api_log.enabled')) {
            try {
                $this->record($request, $response, $ulid, (int) ((hrtime(true) - $started) / 1_000_000));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $response;
    }

    private function record(Request $request, Response $response, string $ulid, int $durationMs): void
    {
        $token = $request->attributes->get('marketing_api_token');

        if (! $token instanceof ApiToken) {
            return;
        }

        $uri = $request->route()?->uri();
        $keepsBodies = $this->keepsBodies($request, $response);
        $agent = $request->userAgent();

        ApiRequest::create([
            'ulid' => $ulid,
            'workspace_id' => $token->workspace_id,
            'api_token_id' => $token->id,
            'token_name' => $token->name,
            'method' => $request->getMethod(),
            'route' => $uri === null ? null : Str::after($uri, trim((string) config('marketing.api_prefix'), '/').'/'),
            'path' => mb_strcut($request->getRequestUri(), 0, 2048),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'ip' => $request->ip(),
            'user_agent' => $agent === null ? null : Str::limit($agent, 255, ''),
            'request_body' => $keepsBodies ? $this->cut($request->getContent()) : null,
            'response_body' => $keepsBodies ? $this->cut($response->getContent() ?: null) : null,
        ]);
    }

    /**
     * Bodies help debugging for writes and errors. A successful read is a dump of contact data, so it keeps none.
     */
    private function keepsBodies(Request $request, Response $response): bool
    {
        return ! in_array($request->getMethod(), ['GET', 'HEAD'], true) || $response->getStatusCode() >= 400;
    }

    private function cut(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        $max = (int) config('marketing.api_log.max_body_bytes');

        if (strlen($body) <= $max) {
            return $body;
        }

        return mb_strcut($body, 0, $max)."\n… cut, ".(strlen($body) - $max).' more bytes';
    }
}
