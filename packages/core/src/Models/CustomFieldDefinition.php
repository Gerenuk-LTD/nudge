<?php

namespace Gerenuk\Marketing\Models;

use Gerenuk\Marketing\Enums\CustomFieldType;
use Gerenuk\Marketing\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Declares a custom field. Values live in contacts.custom_fields under `key`.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $key
 * @property string $label
 * @property CustomFieldType $type
 */
#[Fillable(['workspace_id', 'key', 'label', 'type'])]
class CustomFieldDefinition extends Model
{
    use BelongsToWorkspace;

    /** Keys are used inside JSON paths, so they are restricted to safe characters. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    protected function casts(): array
    {
        return [
            'type' => CustomFieldType::class,
        ];
    }
}
