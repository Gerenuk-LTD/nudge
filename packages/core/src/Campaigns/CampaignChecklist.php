<?php

namespace Gerenuk\Nudge\Campaigns;

use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\CustomFieldDefinition;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Sending\LinkExtractor;
use Gerenuk\Nudge\Sending\MergeTags;
use Gerenuk\Nudge\Sending\WebhookVerification;

/**
 * Checks run before a campaign can be sent. Blocking failures stop the send; the rest are warnings.
 */
class CampaignChecklist
{
    public function __construct(private readonly CampaignAudience $audience) {}

    /**
     * @return list<ChecklistItem>
     */
    public function for(Campaign $campaign, Workspace $workspace): array
    {
        $provider = $campaign->provider;
        $html = (string) $campaign->html;
        $audienceCount = $this->audience->count($campaign);
        $tags = MergeTags::tagsIn($html);
        $personalised = implode("\n", array_filter([(string) $campaign->subject, (string) $campaign->preheader, $html, $campaign->ab_enabled ? (string) $campaign->ab_subject_b : '', $campaign->ab_enabled ? (string) $campaign->ab_html_b : '']));
        $customKeys = array_values(array_map('strval', CustomFieldDefinition::query()->pluck('key')->all()));
        $unfilled = array_values(array_filter(
            MergeTags::unfilledIn($personalised, $customKeys),
            fn (string $tag) => str_starts_with($tag, 'custom.'),
        ));
        $values = $campaign->variables ?? [];
        $missing = array_keys(array_filter(
            MergeTags::variablesIn($personalised, $customKeys),
            fn (bool $hasFallback, string $tag) => ! $hasFallback && blank($values[$tag] ?? null),
            ARRAY_FILTER_USE_BOTH,
        ));

        return [
            new ChecklistItem('provider', 'Sending provider connected', $provider !== null, true,
                $provider === null ? 'Choose the provider this campaign sends through.' : null),
            new ChecklistItem('sender', 'Sender address set', filled($campaign->from_email) && filled($campaign->from_name), true,
                'Set a from name and email address.'),
            new ChecklistItem('subject', 'Subject line written', filled($campaign->subject), true, 'Write a subject line.'),
            new ChecklistItem('content', 'Content written', trim(strip_tags($html)) !== '', true, 'Add some content.'),
            new ChecklistItem('audience', 'Audience has contacts', $audienceCount > 0, true,
                $audienceCount > 0 ? number_format($audienceCount).' contacts will receive it.' : 'No subscribed contacts match this audience.'),
            new ChecklistItem('postal_address', 'Postal address set', filled($workspace->postal_address), true,
                'Anti-spam law requires a postal address in every campaign. Add it in workspace settings.'),
            new ChecklistItem('paused', 'Sending is not paused', ! $workspace->isSendingPaused(), true,
                $workspace->isSendingPaused() ? 'Sending is paused: '.$workspace->sending_paused_reason : null),
            new ChecklistItem('approval', 'Approved', ! $workspace->requires_campaign_approval || $campaign->approved_at !== null, true,
                'This workspace requires campaigns to be approved before sending.'),
            ...($campaign->ab_enabled ? [
                new ChecklistItem('ab_variant', 'A/B test has a variant B', filled($campaign->ab_subject_b) || filled($campaign->ab_html_b), true,
                    'Write a different subject or content for variant B, or switch the test off.'),
                new ChecklistItem('ab_audience', 'Audience is big enough to test', $audienceCount >= 100, false,
                    'With fewer than 100 contacts the test sample is too small for a reliable winner.'),
            ] : []),
            new ChecklistItem('variables', 'Variables have values', $missing === [], true,
                $missing === [] ? null : (string) __('Give these a value in Variables: :tags.', ['tags' => implode(', ', array_map(fn (string $tag) => "{{ {$tag} }}", $missing))])),
            new ChecklistItem('unsubscribe', 'Unsubscribe link placed', in_array('unsubscribe_url', $tags, true), false,
                'No {{ unsubscribe_url }} tag, so an unsubscribe link will be added at the bottom.'),
            new ChecklistItem('merge_tags', 'Every merge tag can be filled', $unfilled === [], false,
                $unfilled === [] ? null : (string) __(
                    'These custom fields don\'t exist, so they will be blank: :tags. Create the field or fix the tag.',
                    ['tags' => implode(', ', array_map(fn (string $tag) => "{{ {$tag} }}", $unfilled))],
                )),
            new ChecklistItem('links', 'Links use https', ! $this->hasInsecureLinks($html), false,
                'Some links use http://, which some inboxes flag.'),
            new ChecklistItem('webhooks', 'Bounces and complaints are tracked', $provider === null || $provider->type->webhookVerification() !== WebhookVerification::None, false,
                'This provider sends no delivery webhooks, so bounces and complaints will not be recorded.'),
        ];
    }

    /**
     * @param  list<ChecklistItem>  $items
     */
    public static function passes(array $items): bool
    {
        foreach ($items as $item) {
            if ($item->blocking && ! $item->passed) {
                return false;
            }
        }

        return true;
    }

    private function hasInsecureLinks(string $html): bool
    {
        return preg_match('#href\s*=\s*["\']http://#i', $html) === 1 && LinkExtractor::urls($html) !== [];
    }
}
