<?php

namespace Gerenuk\Marketing\Models;

use Carbon\CarbonInterface;
use Gerenuk\Marketing\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One authenticated API request and its response, as shown in the workspace's API log. Rows are never updated,
 * except that their bodies are cleared once they're old.
 *
 * @property int $id
 * @property string|null $ulid
 * @property int $workspace_id
 * @property int|null $api_token_id
 * @property string $token_name
 * @property string $method
 * @property string|null $route
 * @property string $path
 * @property int $status
 * @property int $duration_ms
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $request_body
 * @property string|null $response_body
 * @property CarbonInterface|null $created_at
 */
#[Fillable(['ulid', 'workspace_id', 'api_token_id', 'token_name', 'method', 'route', 'path', 'status', 'duration_ms', 'ip', 'user_agent', 'request_body', 'response_body', 'created_at'])]
class ApiRequest extends Model
{
    use BelongsToWorkspace;
    use HasUlids;

    public const UPDATED_AT = null;

    /**
     * The ULID is a second, public identifier; the integer `id` stays the primary key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /**
     * The ID shown to people and sent as the `X-Request-Id` header. Null for rows logged before ULIDs existed.
     */
    public function publicId(): ?string
    {
        return $this->ulid === null ? null : 'req_'.$this->ulid;
    }

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ApiToken, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class, 'api_token_id');
    }
}
