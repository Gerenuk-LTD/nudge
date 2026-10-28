<?php

namespace Gerenuk\Marketing\Models;

use Carbon\CarbonInterface;
use Gerenuk\Marketing\Automations\AutomationDefinition;
use Gerenuk\Marketing\Database\Factories\AutomationFactory;
use Gerenuk\Marketing\Enums\AutomationStatus;
use Gerenuk\Marketing\Enums\AutomationTrigger;
use Gerenuk\Marketing\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property AutomationStatus $status
 * @property AutomationTrigger $trigger_type
 * @property array<string, mixed>|null $trigger_config
 * @property array{steps: list<array<string, mixed>>} $definition
 * @property bool $allow_reentry
 * @property int $entered_count
 * @property int $completed_count
 * @property CarbonInterface|null $activated_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['workspace_id', 'name', 'status', 'trigger_type', 'trigger_config', 'definition', 'allow_reentry', 'activated_at'])]
#[UseFactory(AutomationFactory::class)]
class Automation extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<AutomationFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'definition' => '{"steps":[]}',
        'allow_reentry' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => AutomationStatus::class,
            'trigger_type' => AutomationTrigger::class,
            'trigger_config' => 'array',
            'definition' => 'array',
            'allow_reentry' => 'boolean',
            'activated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AutomationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /**
     * The hidden campaigns that hold each email step's content and stats.
     *
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function steps(): AutomationDefinition
    {
        return AutomationDefinition::from($this->definition);
    }

    public function triggerValue(string $key): mixed
    {
        return ($this->trigger_config ?? [])[$key] ?? null;
    }
}
