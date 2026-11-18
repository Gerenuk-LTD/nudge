<?php

namespace Gerenuk\Nudge\Automations;

use Gerenuk\Nudge\Actions\Contacts\ChangeContactStatus;
use Gerenuk\Nudge\Actions\Contacts\UpsertContact;
use Gerenuk\Nudge\Enums\ContactStatus;
use Gerenuk\Nudge\Enums\RecipientStatus;
use Gerenuk\Nudge\Models\AutomationRun;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\CampaignRecipient;
use Gerenuk\Nudge\Models\Contact;
use Gerenuk\Nudge\Models\ContactList;
use Gerenuk\Nudge\Models\Tag;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Segments\SegmentQuery;
use Gerenuk\Nudge\Sending\DeliveryOutcome;
use Gerenuk\Nudge\Sending\RecipientDelivery;
use Throwable;

/**
 * Carries out one automation step for one contact.
 */
class StepRunner
{
    public function __construct(
        private readonly RecipientDelivery $delivery,
        private readonly SegmentQuery $segments,
        private readonly WebhookCaller $webhooks,
        private readonly AutomationTriggers $triggers,
    ) {}

    /**
     * @param  array<string, mixed>  $step
     */
    public function run(array $step, AutomationRun $run, Contact $contact, Workspace $workspace): StepResult
    {
        $config = is_array($step['config'] ?? null) ? $step['config'] : [];

        return match (StepType::tryFrom((string) ($step['type'] ?? ''))) {
            StepType::SendEmail => $this->sendEmail((string) $step['id'], $run, $contact, $workspace),
            StepType::Wait => StepResult::waitUntil(now()->add((string) $config['unit'], (int) $config['amount'])),
            StepType::Condition => StepResult::branch($this->matches($config, $contact)),
            StepType::UpdateContact => $this->updateContact($config, $contact),
            StepType::Webhook => $this->callWebhook($config, $run, $contact),
            StepType::Exit => StepResult::exit(__('Reached an exit step')),
            null => StepResult::exit(__('Unknown step type')),
        };
    }

    private function sendEmail(string $stepId, AutomationRun $run, Contact $contact, Workspace $workspace): StepResult
    {
        $campaign = Campaign::with(['snapshot', 'provider'])
            ->where('automation_id', $run->automation_id)
            ->where('automation_step_id', $stepId)
            ->first();

        if ($campaign?->snapshot === null || $campaign->provider === null) {
            return StepResult::exit(__('The email step is not ready to send'));
        }

        // Unique per campaign, contact and run, so a retried step never sends twice.
        $recipient = CampaignRecipient::query()->firstOrCreate(
            ['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'automation_run_id' => $run->id],
            ['email' => $contact->email, 'status' => RecipientStatus::Queued],
        );

        if ($recipient->wasRecentlyCreated) {
            $campaign->increment('recipients_count');
        }

        if ($recipient->status !== RecipientStatus::Queued) {
            return StepResult::next();
        }

        $recipient->setRelation('contact', $contact);
        $outcome = $this->delivery->deliver($campaign, $campaign->provider, $campaign->snapshot, $recipient, $workspace, $campaign->links()->get()->keyBy('url_hash')->all());

        // Over the provider's daily limit: stay on this step and try again tomorrow.
        return $outcome === DeliveryOutcome::OverDailyLimit
            ? StepResult::retryAt(now()->addDay()->startOfDay()->addMinutes(5))
            : StepResult::next();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matches(array $config, Contact $contact): bool
    {
        $definition = $config['definition'] ?? null;

        if (! is_array($definition)) {
            return false;
        }

        return $this->segments->contacts($definition)->whereKey($contact->id)->exists();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function updateContact(array $config, Contact $contact): StepResult
    {
        $addTags = array_values(array_filter((array) ($config['add_tags'] ?? []), 'is_string'));
        $removeTags = array_values(array_filter((array) ($config['remove_tags'] ?? []), 'is_string'));
        $addLists = array_values(array_map('intval', (array) ($config['add_list_ids'] ?? [])));
        $removeLists = array_values(array_map('intval', (array) ($config['remove_list_ids'] ?? [])));

        if ($addTags !== []) {
            $attached = $contact->tags()->syncWithoutDetaching(UpsertContact::resolveTagIds($addTags))['attached'];
            $this->triggers->tagsAdded($contact, $attached);
        }

        if ($removeTags !== []) {
            $contact->tags()->detach(Tag::query()->whereIn('name', $removeTags)->pluck('id'));
        }

        if ($addLists !== []) {
            $existing = ContactList::query()->whereKey($addLists)->pluck('id')->all();
            $attached = $contact->lists()->syncWithoutDetaching(UpsertContact::listPivot(array_values(array_map('intval', $existing))))['attached'];
            $this->triggers->listsJoined($contact, $attached);
        }

        if ($removeLists !== []) {
            $contact->lists()->detach($removeLists);
        }

        $fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];

        if ($fields !== []) {
            $contact->custom_fields = [...($contact->custom_fields ?? []), ...$fields];
        }

        if (($config['unsubscribe'] ?? false) === true) {
            (new ChangeContactStatus)->apply($contact, ContactStatus::Unsubscribed);
        }

        $contact->save();

        return StepResult::next();
    }

    /**
     * Webhook failures are recorded on the run but do not stop the automation.
     *
     * @param  array<string, mixed>  $config
     */
    private function callWebhook(array $config, AutomationRun $run, Contact $contact): StepResult
    {
        try {
            $status = $this->webhooks->post((string) ($config['url'] ?? ''), [
                'automation_id' => $run->automation_id,
                'contact' => [
                    'email' => $contact->email,
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                    'custom_fields' => (object) ($contact->custom_fields ?? []),
                ],
                'trigger' => (object) ($run->context ?? []),
            ]);

            return StepResult::next(['webhook_status' => $status]);
        } catch (Throwable $exception) {
            return StepResult::next(['webhook_error' => mb_substr($exception->getMessage(), 0, 255)]);
        }
    }
}
