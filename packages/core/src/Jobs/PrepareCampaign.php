<?php

namespace Gerenuk\Nudge\Jobs;

use Gerenuk\Nudge\Campaigns\CampaignAudience;
use Gerenuk\Nudge\Campaigns\QueueCampaignBatches;
use Gerenuk\Nudge\Enums\CampaignStatus;
use Gerenuk\Nudge\Enums\RecipientStatus;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\CampaignLink;
use Gerenuk\Nudge\Models\CampaignRecipient;
use Gerenuk\Nudge\Models\CampaignSnapshot;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Sending\HtmlToText;
use Gerenuk\Nudge\Sending\LinkExtractor;
use Gerenuk\Nudge\Sending\MergeTags;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Starts a due campaign: freezes its content, records its links, builds the recipient list,
 * then queues the sends in batches.
 */
class PrepareCampaign implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const BATCH_SIZE = 100;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(public int $campaignId, public int $workspaceId)
    {
        $this->onQueue(config('nudge.sending.queue'));
    }

    public static function dispatchFor(Campaign $campaign): void
    {
        static::dispatch($campaign->id, $campaign->workspace_id);
    }

    public function uniqueId(): string
    {
        return (string) $this->campaignId;
    }

    public function handle(CurrentWorkspace $currentWorkspace, CampaignAudience $audience): void
    {
        $workspace = Workspace::findOrFail($this->workspaceId);

        $currentWorkspace->runAs($workspace, function () use ($audience, $workspace): void {
            $campaign = Campaign::find($this->campaignId);

            if ($campaign === null) {
                return;
            }

            // A backstop for anything that withdrew approval after scheduling: such a campaign goes back to Draft unsent.
            if ($workspace->requires_campaign_approval && $campaign->approved_at === null && $campaign->status === CampaignStatus::Scheduled) {
                Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Scheduled)
                    ->update(['status' => CampaignStatus::Draft, 'scheduled_at' => null]);

                return;
            }

            // Claim the campaign, so the scheduler cannot start it twice. A retry of this job carries on
            // where it stopped: every step is safe to repeat, and recipients are claimed one by one when sent.
            $claimed = Campaign::query()
                ->whereKey($campaign->id)
                ->where('status', CampaignStatus::Scheduled)
                ->where('scheduled_at', '<=', now())
                ->update(['status' => CampaignStatus::Sending, 'sending_started_at' => now()]) === 1;

            if (! $claimed && ! ($this->attempts() > 1 && $campaign->status === CampaignStatus::Sending)) {
                return;
            }

            $campaign->refresh();
            $this->snapshot($campaign);
            $this->addRecipients($campaign, $audience);
            $this->queueBatches($campaign);
        });
    }

    private function snapshot(Campaign $campaign): void
    {
        DB::transaction(function () use ($campaign): void {
            if ($campaign->snapshot()->exists()) {
                return;
            }

            $values = $campaign->variables ?? [];
            $html = MergeTags::fill((string) $campaign->html, $values, escapeHtml: true);
            $htmlB = $campaign->ab_enabled && filled($campaign->ab_html_b) ? MergeTags::fill((string) $campaign->ab_html_b, $values, escapeHtml: true) : null;

            CampaignSnapshot::create([
                'campaign_id' => $campaign->id,
                'subject' => MergeTags::fill((string) $campaign->subject, $values, escapeHtml: false),
                'subject_b' => $campaign->ab_enabled && filled($campaign->ab_subject_b) ? MergeTags::fill((string) $campaign->ab_subject_b, $values, escapeHtml: false) : null,
                'preheader' => $campaign->preheader === null ? null : MergeTags::fill($campaign->preheader, $values, escapeHtml: false),
                'from_name' => (string) $campaign->from_name,
                'from_email' => (string) $campaign->from_email,
                'reply_to' => $campaign->reply_to,
                'html' => $html,
                'html_b' => $htmlB,
                'text' => filled($campaign->text) ? MergeTags::fill((string) $campaign->text, $values, escapeHtml: false) : HtmlToText::convert($html),
                'text_b' => $htmlB === null ? null : HtmlToText::convert($htmlB),
            ]);

            if ($campaign->track_clicks) {
                foreach (array_unique([...LinkExtractor::urls($html), ...LinkExtractor::urls((string) $htmlB)]) as $url) {
                    CampaignLink::firstOrCreate(
                        ['campaign_id' => $campaign->id, 'url_hash' => LinkExtractor::hash($url)],
                        ['url' => $url],
                    );
                }
            }
        });
    }

    /**
     * Recipient rows are unique per campaign and contact, so a retried job adds nobody twice.
     */
    private function addRecipients(Campaign $campaign, CampaignAudience $audience): void
    {
        $audience->query($campaign)->select(['id', 'email'])->chunkById(1000, function (Collection $contacts) use ($campaign): void {
            $now = now();

            CampaignRecipient::query()->insertOrIgnore($contacts->map(fn (Contact $contact) => [
                'workspace_id' => $campaign->workspace_id,
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'email' => $contact->email,
                'status' => RecipientStatus::Queued->value,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        $campaign->update(['recipients_count' => $campaign->recipients()->count()]);

        if ($campaign->ab_enabled && $campaign->ab_winner === null) {
            $this->splitForAbTest($campaign);
        }
    }

    /**
     * Send A and B to a random sample, split evenly, and hold everyone else until a winner is picked.
     * Skipped on a retry that already split the audience.
     */
    private function splitForAbTest(Campaign $campaign): void
    {
        if ($campaign->recipients()->whereNotNull('variant')->exists()) {
            return;
        }

        $sampleSize = max(2, (int) round($campaign->recipients_count * $campaign->ab_sample_percent / 100));
        $sample = $campaign->recipients()->inRandomOrder()->limit($sampleSize)->pluck('id')->all();
        $half = intdiv(count($sample), 2);

        DB::transaction(function () use ($campaign, $sample, $half): void {
            CampaignRecipient::query()->whereKey(array_slice($sample, 0, $half))->update(['variant' => 'a']);
            CampaignRecipient::query()->whereKey(array_slice($sample, $half))->update(['variant' => 'b']);
            $campaign->recipients()->whereNull('variant')->update(['status' => RecipientStatus::Held]);
        });

        PickAbTestWinner::dispatch($campaign->id, $campaign->workspace_id)->delay(now()->addMinutes($campaign->ab_wait_minutes));
    }

    private function queueBatches(Campaign $campaign): void
    {
        app(QueueCampaignBatches::class)->handle($campaign);

        if ($campaign->recipients_count === 0) {
            $campaign->update(['status' => CampaignStatus::Sent, 'sent_at' => now()]);
        }
    }
}
