<?php

namespace Gerenuk\Nudge\Actions\Campaigns;

use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\CampaignRecipient;
use Gerenuk\Nudge\Models\CampaignSnapshot;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\Suppression;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Sending\CampaignMessage;
use Gerenuk\Nudge\Sending\HtmlToText;
use Gerenuk\Nudge\Sending\MergeTags;
use Gerenuk\Nudge\Sending\ProviderTransports;
use Gerenuk\Nudge\Sending\SendRateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

class SendTestEmail
{
    public function __construct(
        private readonly ProviderTransports $transports,
        private readonly CampaignMessage $messages,
        private readonly SendRateLimiter $rateLimiter,
    ) {}

    /**
     * Send the current draft to a few addresses. Merge tags use the matching contact when one exists.
     * Tracking is off and the unsubscribe link goes nowhere, so tests never affect real data. Tests still respect
     * the workspace's sending pause, suppressions and the provider's daily limit.
     *
     * @param  list<string>  $emails
     */
    public function handle(Campaign $campaign, Workspace $workspace, array $emails): void
    {
        $provider = $campaign->provider
            ?? throw ValidationException::withMessages(['emails' => __('Choose a sending provider first.')]);

        if ($workspace->isSendingPaused()) {
            throw ValidationException::withMessages(['emails' => __('Sending is paused for this workspace, so tests are paused too.')]);
        }

        $emails = array_values(array_filter(
            $emails,
            fn (string $email) => ! Suppression::query()->where('email', Contact::normalizeEmail($email))->exists(),
        ));

        if ($emails === []) {
            throw ValidationException::withMessages(['emails' => __('These addresses are suppressed and can\'t be emailed.')]);
        }

        $draft = (clone $campaign)->forceFill(['track_opens' => false, 'track_clicks' => false]);
        $values = $campaign->variables ?? [];
        $html = MergeTags::fill((string) $campaign->html, $values, escapeHtml: true);
        $snapshot = new CampaignSnapshot([
            'subject' => '[Test] '.MergeTags::fill((string) $campaign->subject, $values, escapeHtml: false),
            'preheader' => $campaign->preheader === null ? null : MergeTags::fill($campaign->preheader, $values, escapeHtml: false),
            'from_name' => (string) $campaign->from_name,
            'from_email' => (string) $campaign->from_email,
            'reply_to' => $campaign->reply_to,
            'html' => $html,
            'text' => filled($campaign->text) ? MergeTags::fill((string) $campaign->text, $values, escapeHtml: false) : HtmlToText::convert($html),
        ]);

        foreach ($emails as $email) {
            $contact = Contact::query()->forEmail($email)->first() ?? new Contact(['email' => $email]);
            $recipient = (new CampaignRecipient(['email' => Contact::normalizeEmail($email)]))->forceFill(['id' => 0]);

            if (! $this->rateLimiter->allowsToday($provider)) {
                throw ValidationException::withMessages(['emails' => __('This provider has reached its daily sending limit. Try again tomorrow.')]);
            }

            try {
                $this->transports->for($provider)->send(
                    $this->messages->build($draft, $snapshot, $recipient, $contact, $workspace, [], $provider->credentials['configuration_set'] ?? null),
                );
            } catch (Throwable $exception) {
                throw ValidationException::withMessages(['emails' => __('The provider rejected the test: :message', ['message' => $exception->getMessage()])]);
            }

            $this->rateLimiter->recordSend($provider);
        }
    }
}
