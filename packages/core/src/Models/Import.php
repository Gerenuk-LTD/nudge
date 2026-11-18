<?php

namespace Gerenuk\Nudge\Models;

use Carbon\CarbonInterface;
use Gerenuk\Nudge\Enums\ImportStatus;
use Gerenuk\Nudge\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A CSV import. Column mapping values are `email`, `first_name`, `last_name`,
 * `status`, `tags`, `custom:<key>` or null to ignore the column.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property int|null $contact_list_id
 * @property string $file_path
 * @property string $original_filename
 * @property list<string> $headers
 * @property array<int, string|null>|null $column_mapping
 * @property list<string>|null $tags
 * @property ImportStatus $status
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $failed_count
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface $created_at
 */
#[Fillable([
    'workspace_id', 'user_id', 'contact_list_id', 'file_path', 'original_filename', 'headers',
    'column_mapping', 'tags', 'status', 'total_rows', 'processed_rows', 'created_count',
    'updated_count', 'failed_count', 'started_at', 'finished_at',
])]
class Import extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'column_mapping' => 'array',
            'tags' => 'array',
            'status' => ImportStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ContactList, $this>
     */
    public function contactList(): BelongsTo
    {
        return $this->belongsTo(ContactList::class);
    }

    /**
     * @return HasMany<ImportError, $this>
     */
    public function errors(): HasMany
    {
        return $this->hasMany(ImportError::class);
    }
}
