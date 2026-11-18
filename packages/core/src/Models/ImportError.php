<?php

namespace Gerenuk\Marketing\Models;

use Gerenuk\Marketing\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $import_id
 * @property int $row_number
 * @property string|null $email
 * @property string $message
 */
#[Fillable(['workspace_id', 'import_id', 'row_number', 'email', 'message', 'created_at'])]
class ImportError extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;
}
