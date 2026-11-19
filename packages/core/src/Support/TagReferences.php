<?php

namespace Gerenuk\Nudge\Support;

use Gerenuk\Nudge\Automations\StepType;
use Gerenuk\Nudge\Enums\AutomationTrigger;
use Gerenuk\Nudge\Models\Automation;
use Gerenuk\Nudge\Models\Segment;
use Gerenuk\Nudge\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Where tags are used outside contacts: segment rules and automation conditions refer to a tag by id, an automation can
 * start when a tag is added, and "update contact" steps add or remove tags by name. Renaming or merging a tag keeps
 * all of these pointing at it.
 */
class TagReferences
{
    /**
     * The segments and automations that use each tag.
     *
     * @param  array<int, string>  $tagNames  Names keyed by tag id
     * @return array<int, list<array{type: 'segment'|'automation', id: int, name: string}>>
     */
    public function usage(array $tagNames): array
    {
        $usage = array_fill_keys(array_keys($tagNames), []);

        foreach (Segment::query()->orderBy('name')->get(['id', 'name', 'definition']) as $segment) {
            foreach ($this->idsInRules($segment->definition) as $tagId) {
                if (isset($usage[$tagId])) {
                    $usage[$tagId][] = ['type' => 'segment', 'id' => $segment->id, 'name' => $segment->name];
                }
            }
        }

        $idsByName = array_change_key_case(array_flip($tagNames));

        foreach (Automation::query()->orderBy('name')->get() as $automation) {
            $ids = $this->idsInAutomation($automation, $idsByName);

            foreach (array_unique($ids) as $tagId) {
                if (isset($usage[$tagId])) {
                    $usage[$tagId][] = ['type' => 'automation', 'id' => $automation->id, 'name' => $automation->name];
                }
            }
        }

        return $usage;
    }

    /**
     * Point automation steps that add or remove a tag by name at its new name.
     */
    public function renamed(string $from, string $to): void
    {
        foreach (Automation::query()->get() as $automation) {
            $steps = $this->mapSteps($automation->definition['steps'] ?? [], fn (array $step) => $this->renameInStep($step, $from, $to));

            if ($steps !== ($automation->definition['steps'] ?? [])) {
                $automation->update(['definition' => ['steps' => $steps]]);
            }
        }
    }

    /**
     * Move every contact and reference from one tag to another, then delete the first.
     */
    public function merge(Tag $from, Tag $into): void
    {
        DB::transaction(function () use ($from, $into): void {
            $contactIds = $from->contacts()->pluck('contacts.id');
            $into->contacts()->syncWithoutDetaching($contactIds);

            foreach (Segment::query()->get() as $segment) {
                $definition = $this->replaceInRules($segment->definition, $from->id, $into->id);

                if ($definition !== $segment->definition) {
                    $segment->update(['definition' => $definition]);
                }
            }

            foreach (Automation::query()->get() as $automation) {
                $changes = [];

                if ($automation->trigger_type === AutomationTrigger::TagAdded && (int) $automation->triggerValue('tag_id') === $from->id) {
                    $changes['trigger_config'] = [...$automation->trigger_config ?? [], 'tag_id' => $into->id];
                }

                $steps = $this->mapSteps($automation->definition['steps'] ?? [], function (array $step) use ($from, $into): array {
                    $step = $this->renameInStep($step, $from->name, $into->name);

                    if (($step['type'] ?? null) === StepType::Condition->value && is_array($step['config']['definition'] ?? null)) {
                        $step['config']['definition'] = $this->replaceInRules($step['config']['definition'], $from->id, $into->id);
                    }

                    return $step;
                });

                if ($steps !== ($automation->definition['steps'] ?? [])) {
                    $changes['definition'] = ['steps' => $steps];
                }

                if ($changes !== []) {
                    $automation->update($changes);
                }
            }

            $from->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $group
     * @return list<int>
     */
    private function idsInRules(array $group): array
    {
        $ids = [];

        foreach ($group['conditions'] ?? [] as $item) {
            if (($item['type'] ?? null) === 'group') {
                $ids = [...$ids, ...$this->idsInRules($item)];
            } elseif (($item['field'] ?? null) === 'tag' && isset($item['value'])) {
                $ids[] = (int) $item['value'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function replaceInRules(array $group, int $from, int $to): array
    {
        $group['conditions'] = array_map(function (array $item) use ($from, $to): array {
            if (($item['type'] ?? null) === 'group') {
                return $this->replaceInRules($item, $from, $to);
            }

            if (($item['field'] ?? null) === 'tag' && (int) ($item['value'] ?? 0) === $from) {
                $item['value'] = $to;
            }

            return $item;
        }, $group['conditions'] ?? []);

        return $group;
    }

    /**
     * @param  array<string, int>  $idsByName  Tag ids keyed by lowercased name
     * @return list<int>
     */
    private function idsInAutomation(Automation $automation, array $idsByName): array
    {
        $ids = [];

        if ($automation->trigger_type === AutomationTrigger::TagAdded && $automation->triggerValue('tag_id') !== null) {
            $ids[] = (int) $automation->triggerValue('tag_id');
        }

        foreach ($automation->steps()->all() as $step) {
            $config = $step['config'] ?? [];

            if (($step['type'] ?? null) === StepType::Condition->value && is_array($config['definition'] ?? null)) {
                $ids = [...$ids, ...$this->idsInRules($config['definition'])];
            }

            foreach ([...(array) ($config['add_tags'] ?? []), ...(array) ($config['remove_tags'] ?? [])] as $name) {
                if (is_string($name) && isset($idsByName[mb_strtolower($name)])) {
                    $ids[] = $idsByName[mb_strtolower($name)];
                }
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function renameInStep(array $step, string $from, string $to): array
    {
        foreach (['add_tags', 'remove_tags'] as $key) {
            if (is_array($step['config'][$key] ?? null)) {
                $step['config'][$key] = array_values(array_unique(array_map(
                    fn (mixed $name) => is_string($name) && mb_strtolower($name) === mb_strtolower($from) ? $to : $name,
                    $step['config'][$key],
                )));
            }
        }

        return $step;
    }

    /**
     * Apply a change to every step, including those inside condition branches.
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     * @return list<array<string, mixed>>
     */
    private function mapSteps(array $steps, callable $change): array
    {
        return array_map(function (array $step) use ($change): array {
            $step = $change($step);

            foreach (['yes', 'no'] as $branch) {
                if (is_array($step[$branch] ?? null)) {
                    $step[$branch] = $this->mapSteps(array_values($step[$branch]), $change);
                }
            }

            return $step;
        }, $steps);
    }
}
