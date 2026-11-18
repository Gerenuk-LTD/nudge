<?php

namespace Gerenuk\Nudge\Automations;

use Gerenuk\Nudge\Enums\AutomationStatus;
use Gerenuk\Nudge\Enums\CampaignKind;
use Gerenuk\Nudge\Enums\CampaignStatus;
use Gerenuk\Nudge\Models\Automation;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\CampaignLink;
use Gerenuk\Nudge\Models\CampaignSnapshot;
use Gerenuk\Nudge\Sending\HtmlToText;
use Gerenuk\Nudge\Sending\LinkExtractor;
use Illuminate\Support\Facades\DB;

/**
 * Each email step is backed by a hidden campaign, so automation emails reuse the campaign
 * sending, tracking and reporting. This keeps those campaigns in step with the automation.
 */
class SyncAutomationEmails
{
    public function handle(Automation $automation): void
    {
        DB::transaction(function () use ($automation): void {
            $live = $automation->status === AutomationStatus::Active;

            foreach ($automation->steps()->ofType(StepType::SendEmail) as $step) {
                $config = is_array($step['config'] ?? null) ? $step['config'] : [];

                $campaign = Campaign::query()->firstOrNew([
                    'automation_id' => $automation->id,
                    'automation_step_id' => (string) $step['id'],
                ]);

                $campaign->fill([
                    'kind' => CampaignKind::Automation,
                    'name' => $automation->name.': '.($config['subject'] ?? 'Email'),
                    'provider_id' => $config['provider_id'] ?? null,
                    'subject' => $config['subject'] ?? null,
                    'preheader' => $config['preheader'] ?? null,
                    'from_name' => $config['from_name'] ?? null,
                    'from_email' => $config['from_email'] ?? null,
                    'reply_to' => $config['reply_to'] ?? null,
                    'html' => $config['html'] ?? null,
                    'track_opens' => (bool) ($config['track_opens'] ?? true),
                    'track_clicks' => (bool) ($config['track_clicks'] ?? true),
                    'status' => $live ? CampaignStatus::Sending : CampaignStatus::Draft,
                    'sending_started_at' => $campaign->sending_started_at ?? ($live ? now() : null),
                ])->save();

                if ($live) {
                    $this->publish($campaign);
                }
            }
        });
    }

    /**
     * Replace the step's snapshot with its current content. Unlike a broadcast, an automation email
     * can be edited while live; the snapshot is what it sends from now on.
     */
    private function publish(Campaign $campaign): void
    {
        $html = (string) $campaign->html;

        $campaign->snapshot()->delete();

        CampaignSnapshot::create([
            'campaign_id' => $campaign->id,
            'subject' => (string) $campaign->subject,
            'preheader' => $campaign->preheader,
            'from_name' => (string) $campaign->from_name,
            'from_email' => (string) $campaign->from_email,
            'reply_to' => $campaign->reply_to,
            'html' => $html,
            'text' => HtmlToText::convert($html),
        ]);

        foreach (LinkExtractor::urls($html) as $url) {
            CampaignLink::firstOrCreate(
                ['campaign_id' => $campaign->id, 'url_hash' => LinkExtractor::hash($url)],
                ['url' => $url],
            );
        }

        $campaign->unsetRelation('snapshot');
    }
}
