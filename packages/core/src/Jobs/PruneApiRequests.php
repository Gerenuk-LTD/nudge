<?php

namespace Gerenuk\Marketing\Jobs;

use Gerenuk\Marketing\Models\ApiRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * Runs daily and trims the API log in every workspace. Request and response bodies hold contact data, so they are
 * cleared sooner than the rows (time, token, endpoint, status), which are deleted after a longer period.
 *
 * Both steps work through the rows in batches of keys, so a large backlog never becomes one huge statement, on
 * SQLite or MySQL.
 */
class PruneApiRequests
{
    public function __invoke(): void
    {
        ApiRequest::withoutGlobalScopes()
            ->where('created_at', '<', now()->subDays((int) config('marketing.api_log.body_retention_days')))
            ->where(fn (Builder $query) => $query->whereNotNull('request_body')->orWhereNotNull('response_body'))
            ->chunkById(1000, fn ($rows) => ApiRequest::withoutGlobalScopes()
                ->whereKey($rows->modelKeys())
                ->update(['request_body' => null, 'response_body' => null]));

        ApiRequest::withoutGlobalScopes()
            ->where('created_at', '<', now()->subDays((int) config('marketing.api_log.retention_days')))
            ->chunkById(1000, fn ($rows) => ApiRequest::withoutGlobalScopes()->whereKey($rows->modelKeys())->delete());
    }
}
