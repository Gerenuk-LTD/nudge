<?php

namespace Gerenuk\Marketing\Actions\Workspaces;

use Carbon\CarbonInterface;
use Gerenuk\Marketing\Contracts\MarketingUser;
use Gerenuk\Marketing\Models\ApiToken;
use Gerenuk\Marketing\Models\Workspace;
use Illuminate\Support\Str;

class CreateApiToken
{
    /**
     * Create a token and return it with its plain value, which is shown once and never stored.
     *
     * @param  list<string>  $permissions
     * @return array{token: ApiToken, plainToken: string}
     */
    public function handle(Workspace $workspace, MarketingUser $creator, string $name, array $permissions, ?CarbonInterface $expiresAt = null): array
    {
        $plainToken = ApiToken::PREFIX.Str::random(48);

        $token = ApiToken::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $creator->getAuthIdentifier(),
            'name' => $name,
            'token_hash' => hash('sha256', $plainToken),
            'permissions' => $permissions,
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'plainToken' => $plainToken];
    }
}
