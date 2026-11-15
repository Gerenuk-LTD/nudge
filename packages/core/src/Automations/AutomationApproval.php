<?php

namespace Gerenuk\Marketing\Automations;

use Gerenuk\Marketing\Models\Automation;

/**
 * What an approver signs off on for an automation: everything that decides who it reaches and what happens to them
 * (the trigger, re-entry, and every step in order, including conditions, branches, webhooks and contact updates).
 * Only the automation's name is left out.
 */
class AutomationApproval
{
    public static function fingerprint(Automation $automation): string
    {
        return hash('sha256', (string) json_encode([
            'trigger' => $automation->trigger_type->value,
            'config' => self::sorted($automation->trigger_config ?? []),
            'allow_reentry' => (bool) $automation->allow_reentry,
            'steps' => self::sorted($automation->definition['steps'] ?? []),
        ]));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
