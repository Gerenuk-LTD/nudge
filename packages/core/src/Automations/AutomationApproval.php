<?php

namespace Gerenuk\Marketing\Automations;

use Gerenuk\Marketing\Models\Automation;

/**
 * What an approver signs off on for an automation: who it reaches (the trigger) and what it sends
 * (each email step). Delays, conditions, webhooks and the name don't change what recipients see.
 */
class AutomationApproval
{
    /** Email step settings that change what recipients see. */
    private const EMAIL_FIELDS = ['subject', 'preheader', 'from_name', 'from_email', 'reply_to', 'html', 'provider_id'];

    public static function fingerprint(Automation $automation): string
    {
        $emails = [];

        foreach ($automation->steps()->ofType(StepType::SendEmail) as $step) {
            $config = is_array($step['config'] ?? null) ? $step['config'] : [];
            $email = ['id' => (string) $step['id']];

            foreach (self::EMAIL_FIELDS as $field) {
                $email[$field] = $config[$field] ?? null;
            }

            $emails[] = $email;
        }

        usort($emails, fn (array $a, array $b) => strcmp($a['id'], $b['id']));

        return hash('sha256', (string) json_encode([
            'trigger' => $automation->trigger_type->value,
            'config' => self::sorted($automation->trigger_config ?? []),
            'emails' => $emails,
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
