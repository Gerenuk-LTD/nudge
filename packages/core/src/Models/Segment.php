<?php

namespace Gerenuk\Marketing\Models;

use Carbon\CarbonInterface;
use Gerenuk\Marketing\Database\Factories\SegmentFactory;
use Gerenuk\Marketing\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A dynamic segment: the definition is re-evaluated every time it is used.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property array<string, mixed> $definition
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['workspace_id', 'name', 'definition'])]
#[UseFactory(SegmentFactory::class)]
class Segment extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<SegmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }
}
