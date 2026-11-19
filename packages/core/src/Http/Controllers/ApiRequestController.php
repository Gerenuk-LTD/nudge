<?php

namespace Gerenuk\Nudge\Http\Controllers;

use Gerenuk\Nudge\Enums\Permission;
use Gerenuk\Nudge\Models\ApiRequest;
use Gerenuk\Nudge\Models\ApiToken;
use Gerenuk\Nudge\Support\TableQuery;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ApiRequestController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function index(Request $request): Response
    {
        return Inertia::render('nudge/workspace/api-log', [
            'requests' => Inertia::defer(fn () => $this->requests($request)),
            'tokens' => ApiToken::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (ApiToken $token) => ['value' => (string) $token->id, 'label' => $token->name])
                ->values(),
            'routes' => ApiRequest::query()->whereNotNull('route')->distinct()->orderBy('route')->pluck('route')
                ->map(fn (string $route) => ['value' => $route, 'label' => $route])
                ->values(),
            'methods' => $this->methods(),
            'retentionDays' => (int) config('nudge.api_log.retention_days'),
            'bodyRetentionDays' => (int) config('nudge.api_log.body_retention_days'),
            'canViewBodies' => $this->canViewBodies($request),
        ]);
    }

    /**
     * The methods in this workspace's log: the common ones in their usual order, then any others alphabetically.
     *
     * @return Collection<int, array{value: string, label: string}>
     */
    private function methods(): Collection
    {
        $order = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

        return ApiRequest::query()->distinct()->pluck('method')
            ->sortBy(fn (string $method) => [in_array($method, $order, true) ? array_search($method, $order, true) : count($order), $method])
            ->map(fn (string $method) => ['value' => $method, 'label' => $method])
            ->values();
    }

    /**
     * One request's bodies and connection details, loaded when its row is expanded.
     */
    public function show(Request $request, ApiRequest $apiRequest): JsonResponse
    {
        $missing = match (true) {
            ! $this->canViewBodies($request) => 'hidden',
            in_array($apiRequest->method, ['GET', 'HEAD'], true) && $apiRequest->status < 400 => 'not_kept',
            $apiRequest->request_body === null && $apiRequest->response_body === null
                && $apiRequest->created_at?->lt(now()->subDays((int) config('nudge.api_log.body_retention_days'))) => 'cleared',
            default => null,
        };

        $showBodies = $missing === null;

        return response()->json([
            'request_body' => $showBodies ? $apiRequest->request_body : null,
            'response_body' => $showBodies ? $apiRequest->response_body : null,
            'ip' => $apiRequest->ip,
            'user_agent' => $apiRequest->user_agent,
            'route' => $apiRequest->route,
            'request_id' => $apiRequest->publicId(),
            'bodies_missing' => $missing,
        ]);
    }

    /**
     * One page of the log, newest first, filtered from the query string. Bodies are left out.
     *
     * @return LengthAwarePaginator<int, array{id: int, token_name: string, method: string, path: string, status: int, duration_ms: int, request_id: string|null, created_at: string|null}>
     */
    private function requests(Request $request): LengthAwarePaginator
    {
        $search = trim($request->string('search')->toString());
        $byId = preg_match('/^(req_)?[0-9a-hjkmnp-tv-z]{26}$/i', $search) === 1;

        $query = ApiRequest::query()
            // A request looked up by its ID is found whatever its age.
            ->unless($byId, fn (Builder $query) => $query->where('created_at', '>=', match ($request->string('period')->toString()) {
                '24h' => now()->subDay(),
                '30d' => now()->subDays(30),
                default => now()->subDays(7),
            }))
            ->when(TableQuery::values($request->input('token')), fn (Builder $query, array $ids) => $query->whereIn('api_token_id', $ids))
            ->when(TableQuery::values($request->input('route')), fn (Builder $query, array $routes) => $query->whereIn('route', $routes))
            ->when(TableQuery::values($request->input('method')), fn (Builder $query, array $methods) => $query->whereIn('method', array_map('strtoupper', $methods)))
            ->when(TableQuery::values($request->input('status')), function (Builder $query, array $statuses): void {
                $ranges = array_intersect_key(
                    ['success' => [200, 399], 'client_error' => [400, 499], 'server_error' => [500, 599]],
                    array_flip($statuses),
                );

                $query->where(function (Builder $query) use ($ranges): void {
                    foreach ($ranges as [$from, $to]) {
                        $query->orWhereBetween('status', [$from, $to]);
                    }
                });
            });

        if ($byId) {
            $query->where('ulid', Str::of($search)->lower()->after('req_')->toString());
        } else {
            TableQuery::search($query, $search, ['path']);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(TableQuery::perPage($request), ['id', 'ulid', 'created_at', 'token_name', 'method', 'path', 'status', 'duration_ms'])
            ->withQueryString()
            ->through(fn (ApiRequest $row) => [
                ...$row->only(['id', 'token_name', 'method', 'path', 'status', 'duration_ms']),
                'request_id' => $row->publicId(),
                'created_at' => $row->created_at?->toIso8601String(),
            ]);
    }

    private function canViewBodies(Request $request): bool
    {
        return $this->user($request)->hasWorkspacePermission($this->currentWorkspace->getOrFail(), Permission::ContactsView);
    }
}
