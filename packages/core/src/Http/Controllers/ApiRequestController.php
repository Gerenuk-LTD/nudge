<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Models\ApiRequest;
use Gerenuk\Marketing\Models\ApiToken;
use Gerenuk\Marketing\Support\TableQuery;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiRequestController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function index(Request $request): Response
    {
        return Inertia::render('marketing/workspace/api-log', [
            'requests' => Inertia::defer(fn () => $this->requests($request)),
            'tokens' => ApiToken::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (ApiToken $token) => ['value' => (string) $token->id, 'label' => $token->name])
                ->values(),
            'routes' => ApiRequest::query()->select('method', 'route')->distinct()->orderBy('route')->orderBy('method')->get()
                ->map(fn (ApiRequest $row) => ['value' => $row->method.' '.$row->route, 'label' => $row->method.' '.$row->route])
                ->values(),
            'retentionDays' => (int) config('marketing.api_log.retention_days'),
            'bodyRetentionDays' => (int) config('marketing.api_log.body_retention_days'),
            'canViewBodies' => $this->canViewBodies($request),
        ]);
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
                && $apiRequest->created_at?->lt(now()->subDays((int) config('marketing.api_log.body_retention_days'))) => 'cleared',
            default => null,
        };

        $showBodies = $missing === null;

        return response()->json([
            'request_body' => $showBodies ? $apiRequest->request_body : null,
            'response_body' => $showBodies ? $apiRequest->response_body : null,
            'ip' => $apiRequest->ip,
            'user_agent' => $apiRequest->user_agent,
            'route' => $apiRequest->route,
            'bodies_missing' => $missing,
        ]);
    }

    /**
     * One page of the log, newest first, filtered from the query string. Bodies are left out.
     *
     * @return LengthAwarePaginator<int, array{id: int, token_name: string, method: string, path: string, status: int, duration_ms: int, created_at: string|null}>
     */
    private function requests(Request $request): LengthAwarePaginator
    {
        $query = ApiRequest::query()
            ->where('created_at', '>=', match ($request->string('period')->toString()) {
                '24h' => now()->subDay(),
                '30d' => now()->subDays(30),
                default => now()->subDays(7),
            })
            ->when(TableQuery::values($request->input('token')), fn (Builder $query, array $ids) => $query->whereIn('api_token_id', $ids))
            ->when(TableQuery::values($request->input('route')), function (Builder $query, array $routes): void {
                $query->where(function (Builder $query) use ($routes): void {
                    foreach ($routes as $value) {
                        [$method, $route] = array_pad(explode(' ', $value, 2), 2, '');

                        $query->orWhere(fn (Builder $query) => $query->where('method', $method)->where('route', $route));
                    }
                });
            })
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

        $search = trim($request->string('search')->toString());

        if (preg_match('/^#?\d+$/', $search) === 1) {
            $query->whereKey((int) ltrim($search, '#'));
        } else {
            TableQuery::search($query, $search, ['path']);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(TableQuery::perPage($request, 50), ['id', 'created_at', 'token_name', 'method', 'path', 'status', 'duration_ms'])
            ->withQueryString()
            ->through(fn (ApiRequest $row) => [
                ...$row->only(['id', 'token_name', 'method', 'path', 'status', 'duration_ms']),
                'created_at' => $row->created_at?->toIso8601String(),
            ]);
    }

    private function canViewBodies(Request $request): bool
    {
        return $this->user($request)->hasWorkspacePermission($this->currentWorkspace->getOrFail(), Permission::ContactsView);
    }
}
