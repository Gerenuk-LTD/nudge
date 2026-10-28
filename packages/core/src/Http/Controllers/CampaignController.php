<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Actions\Campaigns\ScheduleCampaign;
use Gerenuk\Marketing\Actions\Campaigns\SendTestEmail;
use Gerenuk\Marketing\Campaigns\CampaignAudience;
use Gerenuk\Marketing\Campaigns\CampaignChecklist;
use Gerenuk\Marketing\Campaigns\ChecklistItem;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\RecipientStatus;
use Gerenuk\Marketing\Http\Requests\CampaignRequest;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Models\CampaignLink;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\ContactList;
use Gerenuk\Marketing\Models\CustomFieldDefinition;
use Gerenuk\Marketing\Models\Provider;
use Gerenuk\Marketing\Models\Segment;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    /** Fields that change what recipients see; editing them withdraws an approval. */
    private const CONTENT_FIELDS = ['subject', 'preheader', 'from_name', 'from_email', 'reply_to', 'html', 'text', 'audience_type', 'list_ids', 'segment_id', 'provider_id'];

    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        return Inertia::render('marketing/campaigns/index', [
            'campaigns' => Campaign::query()
                ->when(CampaignStatus::tryFrom($status), fn ($query, CampaignStatus $value) => $query->where('status', $value))
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (Campaign $campaign) => $this->summary($campaign)),
            'status' => $status ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:150']]);
        $provider = Provider::query()->orderBy('id')->first();

        $campaign = Campaign::create([
            'name' => $validated['name'],
            'created_by' => $this->user($request)->getAuthIdentifier(),
            'provider_id' => $provider?->id,
            'from_name' => $provider?->from_name,
            'from_email' => $provider?->from_email,
            'reply_to' => $provider?->reply_to,
            'html' => self::starterHtml(),
        ]);

        return to_route('marketing.campaigns.edit', $campaign);
    }

    public function edit(Campaign $campaign, CampaignChecklist $checklist, CampaignAudience $audience): Response|RedirectResponse
    {
        if (! $campaign->status->isEditable()) {
            return to_route('marketing.campaigns.show', $campaign);
        }

        $workspace = $this->currentWorkspace->getOrFail();

        return Inertia::render('marketing/campaigns/edit', [
            'campaign' => $this->details($campaign),
            'providers' => Provider::query()->orderBy('name')->get()->map(fn (Provider $provider) => [
                'id' => $provider->id,
                'name' => $provider->name,
                'type' => $provider->type->label(),
                'from_name' => $provider->from_name,
                'from_email' => $provider->from_email,
                'reply_to' => $provider->reply_to,
            ]),
            'lists' => ContactList::query()->orderBy('name')->get(['id', 'name']),
            'segments' => Segment::query()->orderBy('name')->get(['id', 'name']),
            'mergeTags' => $this->mergeTags(),
            'checklist' => Inertia::defer(fn () => array_map(fn (ChecklistItem $item) => $item->toArray(), $checklist->for($campaign, $workspace))),
            'audienceCount' => Inertia::defer(fn () => $audience->count($campaign)),
            'requiresApproval' => $workspace->requires_campaign_approval,
        ]);
    }

    public function update(CampaignRequest $request, Campaign $campaign): RedirectResponse
    {
        $this->ensureEditable($campaign);

        $campaign->fill($request->validated());

        if ($campaign->approved_at !== null && $campaign->isDirty(self::CONTENT_FIELDS)) {
            $campaign->fill(['approved_at' => null, 'approved_by' => null]);
        }

        $campaign->save();

        return back();
    }

    public function destroy(Campaign $campaign): RedirectResponse
    {
        abort_unless(in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Cancelled], true), 403, __('Only drafts and cancelled campaigns can be deleted.'));

        $campaign->delete();

        return to_route('marketing.campaigns.index');
    }

    public function duplicate(Request $request, Campaign $campaign): RedirectResponse
    {
        $copy = $campaign->replicate(['status', 'approved_at', 'approved_by', 'scheduled_at', 'sending_started_at', 'sent_at', 'recipients_count']);
        $copy->fill([
            'name' => __(':name (copy)', ['name' => $campaign->name]),
            'status' => CampaignStatus::Draft,
            'created_by' => $this->user($request)->getAuthIdentifier(),
        ]);

        // Sent copies start from what was actually sent.
        if ($campaign->snapshot !== null) {
            $copy->fill(['subject' => $campaign->snapshot->subject, 'html' => $campaign->snapshot->html]);
        }

        $copy->forceFill(array_fill_keys(['sent_count', 'failed_count', 'delivered_count', 'bounced_count', 'complained_count', 'opened_count', 'clicked_count', 'unsubscribed_count'], 0))->save();

        return to_route('marketing.campaigns.edit', $copy);
    }

    public function test(Request $request, Campaign $campaign, SendTestEmail $sendTestEmail): RedirectResponse
    {
        $validated = $request->validate([
            'emails' => ['required', 'array', 'min:1', 'max:5'],
            'emails.*' => ['required', 'email:filter'],
        ]);

        $sendTestEmail->handle($campaign, $this->currentWorkspace->getOrFail(), array_values($validated['emails']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test sent.')]);

        return back();
    }

    public function approve(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->ensureEditable($campaign);

        $campaign->update(['approved_at' => now(), 'approved_by' => $this->user($request)->getAuthIdentifier()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Campaign approved.')]);

        return back();
    }

    public function schedule(Request $request, Campaign $campaign, ScheduleCampaign $scheduleCampaign): RedirectResponse
    {
        $validated = $request->validate([
            'send_at' => ['nullable', 'date', 'after:now'],
        ]);

        $sendAt = isset($validated['send_at']) ? Date::parse($validated['send_at']) : null;

        $scheduleCampaign->handle($campaign, $this->currentWorkspace->getOrFail(), $sendAt);

        Inertia::flash('toast', ['type' => 'success', 'message' => $sendAt ? __('Campaign scheduled.') : __('Campaign is sending.')]);

        return to_route('marketing.campaigns.show', $campaign);
    }

    public function unschedule(Campaign $campaign): RedirectResponse
    {
        $updated = Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Scheduled)
            ->update(['status' => CampaignStatus::Draft, 'scheduled_at' => null]);

        if ($updated === 0) {
            throw ValidationException::withMessages(['campaign' => __('This campaign has already started sending.')]);
        }

        return to_route('marketing.campaigns.edit', $campaign);
    }

    public function cancel(Campaign $campaign): RedirectResponse
    {
        $cancelled = Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Sending)
            ->update(['status' => CampaignStatus::Cancelled]) === 1;

        if ($cancelled) {
            $campaign->recipients()->where('status', RecipientStatus::Queued)->update(['status' => RecipientStatus::Skipped]);
        }

        return to_route('marketing.campaigns.show', $campaign);
    }

    public function show(Request $request, Campaign $campaign): Response|RedirectResponse
    {
        if ($campaign->status === CampaignStatus::Draft) {
            return to_route('marketing.campaigns.edit', $campaign);
        }

        $recipientStatus = RecipientStatus::tryFrom($request->string('recipients')->toString());

        return Inertia::render('marketing/campaigns/show', [
            'campaign' => $this->summary($campaign),
            'snapshot' => $campaign->snapshot?->only(['subject', 'preheader', 'from_name', 'from_email', 'reply_to', 'html']),
            'links' => $campaign->links()->orderByDesc('unique_clicks_count')->orderBy('id')->get()
                ->map(fn (CampaignLink $link) => $link->only(['id', 'url', 'clicks_count', 'unique_clicks_count'])),
            'recipients' => Inertia::defer(fn () => $campaign->recipients()
                ->when($recipientStatus, fn ($query, RecipientStatus $status) => $query->where('status', $status))
                ->orderBy('id')
                ->paginate(50, ['id', 'contact_id', 'email', 'status', 'error', 'sent_at', 'opened_at', 'clicked_at'])
                ->withQueryString()
                ->through(fn (CampaignRecipient $recipient) => [
                    ...$recipient->only(['id', 'contact_id', 'email', 'error']),
                    'status' => $recipient->status->value,
                    'opened' => $recipient->opened_at !== null,
                    'clicked' => $recipient->clicked_at !== null,
                ])),
            'recipientStatus' => $recipientStatus?->value,
        ]);
    }

    private function ensureEditable(Campaign $campaign): void
    {
        if (! $campaign->status->isEditable()) {
            throw ValidationException::withMessages(['campaign' => __('Sent campaigns cannot be changed.')]);
        }

        if ($campaign->status === CampaignStatus::Scheduled) {
            throw ValidationException::withMessages(['campaign' => __('Unschedule the campaign before changing it.')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'subject' => $campaign->subject,
            'status' => $campaign->status->value,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'sent_at' => $campaign->sent_at?->toIso8601String(),
            'created_at' => $campaign->created_at?->toIso8601String(),
            'stats' => [
                'recipients' => $campaign->recipients_count,
                'sent' => $campaign->sent_count,
                'failed' => $campaign->failed_count,
                'delivered' => $campaign->delivered_count,
                'bounced' => $campaign->bounced_count,
                'complained' => $campaign->complained_count,
                'opened' => $campaign->opened_count,
                'clicked' => $campaign->clicked_count,
                'unsubscribed' => $campaign->unsubscribed_count,
            ],
            'track_opens' => $campaign->track_opens,
            'track_clicks' => $campaign->track_clicks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function details(Campaign $campaign): array
    {
        return [
            ...$campaign->only(['id', 'name', 'subject', 'preheader', 'provider_id', 'from_name', 'from_email', 'reply_to', 'html', 'text', 'segment_id', 'track_opens', 'track_clicks']),
            'status' => $campaign->status->value,
            'audience_type' => $campaign->audience_type->value,
            'list_ids' => $campaign->list_ids ?? [],
            'approved_at' => $campaign->approved_at?->toIso8601String(),
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{tag: string, label: string}>
     */
    private function mergeTags(): array
    {
        $tags = [
            ['tag' => '{{ first_name }}', 'label' => 'First name'],
            ['tag' => '{{ last_name }}', 'label' => 'Last name'],
            ['tag' => '{{ email }}', 'label' => 'Email'],
            ['tag' => '{{ unsubscribe_url }}', 'label' => 'Unsubscribe link'],
            ['tag' => '{{ postal_address }}', 'label' => 'Postal address'],
        ];

        foreach (CustomFieldDefinition::query()->orderBy('label')->get() as $field) {
            $tags[] = ['tag' => "{{ custom.{$field->key} }}", 'label' => $field->label];
        }

        return $tags;
    }

    private static function starterHtml(): string
    {
        return <<<'HTML'
            <!DOCTYPE html>
            <html>
            <body style="margin:0;padding:0;background:#f4f4f5;">
              <div style="max-width:600px;margin:0 auto;padding:32px 24px;background:#ffffff;font-family:Arial,sans-serif;font-size:16px;line-height:1.6;color:#18181b;">
                <p>Hi {{ first_name | there }},</p>
                <p>Write your message here.</p>
                <p style="font-size:12px;color:#71717a;">{{ postal_address }}<br><a href="{{ unsubscribe_url }}" style="color:#71717a;">Unsubscribe</a></p>
              </div>
            </body>
            </html>
            HTML;
    }
}
