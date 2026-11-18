<?php

namespace Gerenuk\Marketing\Models;

use Carbon\CarbonInterface;
use Gerenuk\Marketing\Database\Factories\EmailTemplateFactory;
use Gerenuk\Marketing\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved email design. Campaigns and automation emails copy it when they start from it, so editing a
 * template never changes an email already made from it.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property string $name
 * @property string|null $description
 * @property string|null $subject
 * @property string|null $preheader
 * @property string $editor
 * @property array<string, mixed>|null $editor_state
 * @property string $html
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['workspace_id', 'created_by', 'name', 'description', 'subject', 'preheader', 'editor', 'editor_state', 'html'])]
#[UseFactory(EmailTemplateFactory::class)]
class EmailTemplate extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'editor_state' => 'array',
        ];
    }
}
