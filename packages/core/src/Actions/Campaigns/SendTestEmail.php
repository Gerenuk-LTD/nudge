<?php

namespace Gerenuk\Marketing\Actions\Campaigns;

use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\CampaignSnapshot;
use Gerenuk\Marketing\Models\Contact;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Sending\CampaignMessage;
use Gerenuk\Marketing\Sending\HtmlToText;
use Gerenuk\Marketing\Sending\ProviderTransports;
use Illuminate\Validation\ValidationException;
use Throwable;

class SendTestEmail
{
    public function __construct(
        private readonly ProviderTransports $transports,
        private readonly CampaignMessage $messages,
    ) {}

    /**
     * Send the current draft to a few addresses. Merge tags use the matching contact when one exists.
     * Tracking is off and the unsubscribe link goes nowhere, so tests never affect real data.
     *
     * @param  list<string>  $emails
     */
    public function handle(Campaign $campaign, Workspace $workspace, array $emails): void
    {
        $provider = $campaign->provider
            ?? throw ValidationException::withMessages(['emails' => __('Choose a sending provider first.')]);

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

            try {
                $this->transports->for($provider)->send(
                    $this->messages->build($draft, $snapshot, $recipient, $contact, $workspace, [], $provider->credentials['configuration_set'] ?? null),
                );
            } catch (Throwable $exception) {
                throw ValidationException::withMessages(['emails' => __('The provider rejected the test: :message', ['message' => $exception->getMessage()])]);
            }
        }
    }
}
