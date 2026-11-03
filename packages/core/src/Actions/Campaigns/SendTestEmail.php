<?php

namespace Gerenuk\Marketing\Actions\Campaigns;

use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\CampaignSnapshot;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Suppression;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Sending\CampaignMessage;
use Gerenuk\Marketing\Sending\HtmlToText;
use Gerenuk\Marketing\Sending\ProviderTransports;
use Gerenuk\Marketing\Sending\SendRateLimiter;
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
        $snapshot = new CampaignSnapshot([
            'subject' => '[Test] '.$campaign->subject,
            'preheader' => $campaign->preheader,
            'from_name' => (string) $campaign->from_name,
            'from_email' => (string) $campaign->from_email,
            'reply_to' => $campaign->reply_to,
            'html' => (string) $campaign->html,
            'text' => filled($campaign->text) ? (string) $campaign->text : HtmlToText::convert((string) $campaign->html),
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
