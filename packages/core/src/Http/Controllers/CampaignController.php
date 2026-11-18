<?php

namespace Gerenuk\Nudge\Http\Controllers;

use Gerenuk\Nudge\Actions\Campaigns\ScheduleCampaign;
use Gerenuk\Nudge\Actions\Campaigns\SendTestEmail;
use Gerenuk\Nudge\Campaigns\AbTestResults;
use Gerenuk\Nudge\Campaigns\CampaignAudience;
use Gerenuk\Nudge\Campaigns\CampaignChecklist;
use Gerenuk\Nudge\Campaigns\ChecklistItem;
use Gerenuk\Nudge\Enums\AudienceType;
use Gerenuk\Nudge\Enums\CampaignKind;
use Gerenuk\Nudge\Enums\CampaignStatus;
use Gerenuk\Nudge\Enums\Permission;
use Gerenuk\Nudge\Enums\RecipientStatus;
use Gerenuk\Nudge\Http\Requests\CampaignRequest;
use Gerenuk\Nudge\Jobs\PickAbTestWinner;
use Gerenuk\Nudge\Models\Campaign;
use Gerenuk\Nudge\Models\CampaignLink;
use Gerenuk\Nudge\Models\CampaignRecipient;
use Gerenuk\Nudge\Models\ContactList;
use Gerenuk\Nudge\Models\Provider;
use Gerenuk\Nudge\Models\Segment;
use Gerenuk\Nudge\Segments\SegmentDefinitionValidator;
use Gerenuk\Nudge\Sending\MergeTags;
use Gerenuk\Nudge\Support\CampaignSummary;
use Gerenuk\Nudge\Support\JsonChanges;
use Gerenuk\Nudge\Support\MergeTagOptions;
use Gerenuk\Nudge\Support\TableQuery;
use Gerenuk\Nudge\Templates\TemplateChoice;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    /** Fields that change what recipients see; editing them withdraws an approval. */
    private const CONTENT_FIELDS = ['subject', 'preheader', 'from_name', 'from_email', 'reply_to', 'editor_state', 'html', 'text', 'audience_type', 'list_ids', 'segment_id', 'provider_id', 'ab_enabled', 'ab_subject_b', 'ab_html_b', 'variables'];

    /** How many drafts and scheduled campaigns the index shelf shows. */
    private const UPCOMING_LIMIT = 8;

    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    /**
     * Drafts and scheduled campaigns as a shelf of work in progress, above a table of what has gone out.
     */
    public function index(Request $request, TemplateChoice $templateChoice): Response
    {
        $history = [CampaignStatus::Sending, CampaignStatus::Sent, CampaignStatus::Cancelled];
        $statuses = array_intersect(
            TableQuery::values($request->input('status')),
            array_map(fn (CampaignStatus $status) => $status->value, $history),
        );

        $query = Campaign::query()
            ->broadcasts()
            ->whereIn('status', $statuses ?: $history);

        TableQuery::search($query, $request->string('search')->toString(), ['name', 'subject']);

        return Inertia::render('nudge/campaigns/index', [
            'campaigns' => TableQuery::sort($query, $request, [
                'name' => 'name',
                'status' => 'status',
                'recipients' => 'recipients_count',
                'opened' => DB::raw('opened_count * 1.0 / nullif(sent_count, 0)'),
                'clicked' => DB::raw('clicked_count * 1.0 / nullif(sent_count, 0)'),
                'date' => DB::raw('coalesce(sent_at, scheduled_at, created_at)'),
            ])
                ->paginate(TableQuery::perPage($request))
                ->withQueryString()
                ->through(fn (Campaign $campaign) => CampaignSummary::from($campaign)),
            'upcoming' => Campaign::query()
                ->broadcasts()
                ->whereIn('status', [CampaignStatus::Scheduled, CampaignStatus::Draft])
                ->orderByRaw('scheduled_at is null')
                ->orderBy('scheduled_at')
                ->orderByDesc('updated_at')
                ->limit(self::UPCOMING_LIMIT)
                ->get()
                ->map(fn (Campaign $campaign) => CampaignSummary::from($campaign)),
            'templates' => Inertia::optional(fn () => $templateChoice->options()),
        ]);
    }

    public function store(Request $request, TemplateChoice $templateChoice): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'template' => ['nullable', 'string', 'max:50'],
        ]);
        $provider = Provider::query()->orderBy('id')->first();

        $campaign = Campaign::create([
            'name' => $validated['name'],
            'created_by' => $this->user($request)->getAuthIdentifier(),
            'provider_id' => $provider?->id,
            'from_name' => $provider?->from_name,
            'from_email' => $provider?->from_email,
            'reply_to' => $provider?->reply_to,
            ...$templateChoice->content($validated['template'] ?? null),
        ]);

        return to_route('nudge.campaigns.edit', $campaign);
    }

    public function edit(Campaign $campaign, CampaignChecklist $checklist, CampaignAudience $audience, TemplateChoice $templateChoice): Response|RedirectResponse
    {
        if ($campaign->kind === CampaignKind::Automation) {
            return to_route('nudge.automations.edit', (int) $campaign->automation_id);
        }

        if (! $campaign->status->isEditable()) {
            return to_route('nudge.campaigns.show', $campaign);
        }

        $workspace = $this->currentWorkspace->getOrFail();

        return Inertia::render('nudge/campaigns/edit', [
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
            'mergeTags' => MergeTagOptions::all(),
            'builtInTags' => MergeTags::BUILT_IN,
            'templates' => Inertia::optional(fn () => $templateChoice->options()),
            'checklist' => Inertia::defer(fn () => array_map(fn (ChecklistItem $item) => $item->toArray(), $checklist->for($campaign, $workspace))),
            'audienceCount' => Inertia::defer(fn () => $audience->count($campaign)),
            'audienceSizes' => Inertia::defer(fn () => $audience->sizes()),
            'requiresApproval' => $workspace->requires_campaign_approval,
        ]);
    }

    public function update(CampaignRequest $request, Campaign $campaign): RedirectResponse
    {
        $this->ensureEditable($campaign);

        $campaign->fill($request->validated());

        if ($campaign->approved_at !== null && JsonChanges::dirty($campaign, self::CONTENT_FIELDS)) {
            $campaign->fill(['approved_at' => null, 'approved_by' => null]);
        }

        $campaign->save();

        return back();
    }

    public function destroy(Campaign $campaign): RedirectResponse
    {
        abort_if($campaign->kind === CampaignKind::Automation, 404);

        abort_unless(in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Cancelled], true), 403, __('Only drafts and cancelled campaigns can be deleted.'));

        $campaign->delete();

        return to_route('nudge.campaigns.index');
    }

    public function duplicate(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_if($campaign->kind === CampaignKind::Automation, 404);

        $copy = $this->copyOf($request, $campaign, __(':name (copy)', ['name' => $campaign->name]));

        return to_route('nudge.campaigns.edit', $copy);
    }

    /**
     * Draft a "second chance" copy of a sent campaign for the people who received it but didn't open it, through a
     * segment that says so, ready for a new subject line.
     */
    public function resendToNonOpeners(Request $request, Campaign $campaign, SegmentDefinitionValidator $validator): RedirectResponse
    {
        abort_unless($campaign->kind === CampaignKind::Broadcast && $campaign->status === CampaignStatus::Sent, 404);

        $definition = ['match' => 'all', 'conditions' => [
            ['type' => 'condition', 'field' => 'campaign', 'operator' => 'received', 'value' => $campaign->id],
            ['type' => 'condition', 'field' => 'campaign', 'operator' => 'not_opened', 'value' => $campaign->id],
        ]];
        $validator->validate($definition);

        $copy = DB::transaction(function () use ($request, $campaign, $definition): Campaign {
            $segment = $this->segmentNamed(__("Didn't open: :name", ['name' => $campaign->name]), $definition);

            $copy = $this->copyOf($request, $campaign, __(':name (resend)', ['name' => $campaign->name]));
            $copy->update(['audience_type' => AudienceType::Segment, 'segment_id' => $segment->id]);

            return $copy;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __("Resend drafted for people who didn't open. Change the subject line before sending.")]);

        return to_route('nudge.campaigns.edit', $copy);
    }

    /**
     * The segment with this name and definition, reused if it already exists. Names are unique, so a different
     * segment that took the name gets a numbered one instead.
     *
     * @param  array<string, mixed>  $definition
     */
    private function segmentNamed(string $name, array $definition): Segment
    {
        $base = Str::limit($name, 90, '…');

        for ($attempt = 1; ; $attempt++) {
            $candidate = $attempt === 1 ? $base : "{$base} ({$attempt})";
            $existing = Segment::query()->where('name', $candidate)->first();

            if ($existing === null) {
                return Segment::create(['name' => $candidate, 'definition' => $definition]);
            }

            if ($existing->definition == $definition) {
                return $existing;
            }
        }
    }

    /**
     * A draft copy of the campaign, without its sending history. Sent campaigns are copied from what actually went out.
     */
    private function copyOf(Request $request, Campaign $campaign, string $name): Campaign
    {
        $copy = $campaign->replicate(['status', 'approved_at', 'approved_by', 'scheduled_at', 'sending_started_at', 'sent_at', 'recipients_count', 'ab_winner', 'ab_decided_at', 'ab_results']);
        $copy->fill([
            'name' => $name,
            'status' => CampaignStatus::Draft,
            'created_by' => $this->user($request)->getAuthIdentifier(),
        ]);

        // Sent copies start from what was actually sent.
        if ($campaign->snapshot !== null) {
            $copy->fill(['subject' => $campaign->snapshot->subject, 'html' => $campaign->snapshot->html]);
        }

        $copy->forceFill(array_fill_keys(['sent_count', 'failed_count', 'delivered_count', 'bounced_count', 'complained_count', 'opened_count', 'clicked_count', 'unsubscribed_count'], 0))->save();

        return $copy;
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

        $version = $request->validate(['version' => ['nullable', 'string']])['version'] ?? null;

        if ($version !== null && $version !== $campaign->updated_at?->toIso8601String()) {
            throw ValidationException::withMessages(['campaign' => __('This campaign changed after you opened it. Reload it and review it again.')]);
        }

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

        return to_route('nudge.campaigns.show', $campaign);
    }

    /**
     * Pick the A/B winner now instead of waiting for the test window to end.
     */
    public function pickWinner(Campaign $campaign): RedirectResponse
    {
        abort_if($campaign->kind === CampaignKind::Automation, 404);

        abort_unless($campaign->ab_enabled && $campaign->ab_winner === null && $campaign->status === CampaignStatus::Sending, 409);

        PickAbTestWinner::dispatch($campaign->id, $campaign->workspace_id, force: true);

        return to_route('nudge.campaigns.show', $campaign);
    }

    public function unschedule(Campaign $campaign): RedirectResponse
    {
        abort_if($campaign->kind === CampaignKind::Automation, 404);

        $updated = Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Scheduled)
            ->update(['status' => CampaignStatus::Draft, 'scheduled_at' => null]);

        if ($updated === 0) {
            throw ValidationException::withMessages(['campaign' => __('This campaign has already started sending.')]);
        }

        return to_route('nudge.campaigns.edit', $campaign);
    }

    public function cancel(Campaign $campaign): RedirectResponse
    {
        abort_if($campaign->kind === CampaignKind::Automation, 404);

        $cancelled = Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Sending)
            ->update(['status' => CampaignStatus::Cancelled]) === 1;

        if ($cancelled) {
            $campaign->recipients()->where('status', RecipientStatus::Queued)->update(['status' => RecipientStatus::Skipped]);
        }

        return to_route('nudge.campaigns.show', $campaign);
    }

    public function show(Request $request, Campaign $campaign, AbTestResults $abResults): Response|RedirectResponse
    {
        if ($campaign->status === CampaignStatus::Draft) {
            return to_route('nudge.campaigns.edit', $campaign);
        }

        $canViewContacts = $this->user($request)->hasWorkspacePermission($this->currentWorkspace->getOrFail(), Permission::ContactsView);

        return Inertia::render('nudge/campaigns/show', [
            'campaign' => CampaignSummary::from($campaign),
            'snapshot' => $campaign->snapshot?->only(['subject', 'subject_b', 'preheader', 'from_name', 'from_email', 'reply_to', 'html', 'html_b']),
            'abTest' => $campaign->ab_enabled ? [
                'metric' => $campaign->ab_metric,
                'winner' => $campaign->ab_winner,
                'decides_at' => $campaign->sending_started_at?->addMinutes($campaign->ab_wait_minutes)->toIso8601String(),
                'results' => $abResults->for($campaign),
            ] : null,
            'links' => $campaign->links()->orderByDesc('unique_clicks_count')->orderBy('id')->get()
                ->map(fn (CampaignLink $link) => $link->only(['id', 'url', 'clicks_count', 'unique_clicks_count'])),
            'canViewRecipients' => $canViewContacts,
            'recipients' => $canViewContacts ? Inertia::defer(fn () => $this->recipients($request, $campaign)) : null,
        ]);
    }

    /**
     * One page of the campaign's recipients, searched, filtered by status and sorted from the query string.
     *
     * @return LengthAwarePaginator<int, array{id: int, contact_id: int, email: string, error: string|null, status: string, opened: bool, clicked: bool}>
     */
    private function recipients(Request $request, Campaign $campaign): LengthAwarePaginator
    {
        $query = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->when(TableQuery::values($request->input('status')), fn ($query, array $statuses) => $query->whereIn('status', $statuses));

        TableQuery::search($query, $request->string('search')->toString(), ['email']);

        return TableQuery::sort($query, $request, [
            'email' => 'email',
            'status' => 'status',
            'opened' => 'opened_at',
            'clicked' => 'clicked_at',
        ], 'id')
            ->paginate(TableQuery::perPage($request, 50), ['id', 'contact_id', 'email', 'status', 'error', 'sent_at', 'opened_at', 'clicked_at'])
            ->withQueryString()
            ->through(fn (CampaignRecipient $recipient) => [
                ...$recipient->only(['id', 'contact_id', 'email', 'error']),
                'status' => $recipient->status->value,
                'opened' => $recipient->opened_at !== null,
                'clicked' => $recipient->clicked_at !== null,
            ]);
    }

    private function ensureEditable(Campaign $campaign): void
    {
        abort_if($campaign->kind === CampaignKind::Automation, 404);

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
    private function details(Campaign $campaign): array
    {
        return [
            ...$campaign->only([
                'id', 'name', 'subject', 'preheader', 'provider_id', 'from_name', 'from_email', 'reply_to', 'editor', 'editor_state', 'html', 'text', 'segment_id',
                'track_opens', 'track_clicks', 'ab_enabled', 'ab_subject_b', 'ab_html_b', 'ab_editor_state_b', 'ab_sample_percent', 'ab_wait_minutes', 'ab_metric',
            ]),
            'variables' => (object) ($campaign->variables ?? []),
            'status' => $campaign->status->value,
            'audience_type' => $campaign->audience_type->value,
            'list_ids' => $campaign->list_ids ?? [],
            'approved_at' => $campaign->approved_at?->toIso8601String(),
            'updated_at' => $campaign->updated_at?->toIso8601String(),
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
        ];
    }
}
