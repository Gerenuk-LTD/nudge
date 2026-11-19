<?php

namespace Gerenuk\Nudge\Models;

use Carbon\CarbonInterface;
use Gerenuk\Nudge\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * An image uploaded for use in emails, served publicly so inboxes can load it.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $uploaded_by
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string $mime_type
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['workspace_id', 'uploaded_by', 'disk', 'path', 'name', 'mime_type', 'size', 'width', 'height'])]
class EmailAsset extends Model
{
    use BelongsToWorkspace;

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * @return array{id: int, url: string, name: string, width: int|null, height: int|null}
     */
    public function toPicker(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'name' => $this->name,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
