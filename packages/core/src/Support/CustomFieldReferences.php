<?php

namespace Gerenuk\Marketing\Support;

use Gerenuk\Marketing\Automations\StepType;
use Gerenuk\Marketing\Enums\AutomationTrigger;
use Gerenuk\Marketing\Models\Automation;
use Gerenuk\Marketing\Models\Segment;

/**
 * Where custom fields are used outside contacts: segment rules and automation conditions filter on them as
 * "custom:key", an automation can start on a date field, and "update contact" steps can set them.
 */
class CustomFieldReferences
{
    /**
     * The segments and automations that use each field.
     *
     * @param  list<string>  $keys
     * @return array<string, list<array{type: 'segment'|'automation', id: int, name: string}>>
     */
    public function usage(array $keys): array
    {
        $usage = array_fill_keys($keys, []);

        foreach (Segment::query()->orderBy('name')->get(['id', 'name', 'definition']) as $segment) {
            foreach ($this->keysInRules($segment->definition) as $key) {
                if (isset($usage[$key])) {
                    $usage[$key][] = ['type' => 'segment', 'id' => $segment->id, 'name' => $segment->name];
                }
            }
        }

        foreach (Automation::query()->orderBy('name')->get() as $automation) {
            foreach (array_unique($this->keysInAutomation($automation)) as $key) {
                if (isset($usage[$key])) {
                    $usage[$key][] = ['type' => 'automation', 'id' => $automation->id, 'name' => $automation->name];
                }
            }
        }

        return $usage;
    }

    /**
     * @param  array<string, mixed>  $group
     * @return list<string>
     */
    private function keysInRules(array $group): array
    {
        $keys = [];

        foreach ($group['conditions'] ?? [] as $item) {
            if (($item['type'] ?? null) === 'group') {
                $keys = [...$keys, ...$this->keysInRules($item)];
            } elseif (is_string($item['field'] ?? null) && str_starts_with($item['field'], 'custom:')) {
                $keys[] = substr($item['field'], 7);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<string>
     */
    private function keysInAutomation(Automation $automation): array
    {
        $keys = [];

        if ($automation->trigger_type === AutomationTrigger::DateField && is_string($automation->triggerValue('field_key'))) {
            $keys[] = $automation->triggerValue('field_key');
        }

        foreach ($automation->steps()->all() as $step) {
            $config = $step['config'] ?? [];

            if (($step['type'] ?? null) === StepType::Condition->value && is_array($config['definition'] ?? null)) {
                $keys = [...$keys, ...$this->keysInRules($config['definition'])];
            }

            if (is_array($config['fields'] ?? null)) {
                $keys = [...$keys, ...array_map('strval', array_keys($config['fields']))];
            }
        }

        return $keys;
    }
}
