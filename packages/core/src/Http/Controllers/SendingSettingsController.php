<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Campaigns\QueueCampaignBatches;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SendingSettingsController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function edit(Request $request): Response
    {
        $workspace = $this->currentWorkspace->getOrFail();

        return Inertia::render('marketing/workspace/sending', [
            'postal_address' => $workspace->postal_address,
            'requires_campaign_approval' => $workspace->requires_campaign_approval,
            'canChangeApproval' => $this->user($request)->hasWorkspacePermission($workspace, Permission::CampaignsApprove),
            'paused_at' => $workspace->sending_paused_at?->toIso8601String(),
            'paused_reason' => $workspace->sending_paused_reason,
            'thresholds' => config('marketing.sending.auto_pause'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace->getOrFail();
        $validated = $request->validate([
            'postal_address' => ['required', 'string', 'max:500'],
            'requires_campaign_approval' => ['required', 'boolean'],
        ]);
        $approval = (bool) $validated['requires_campaign_approval'];
        $approvalChanged = $approval !== $workspace->requires_campaign_approval;
        $user = $this->user($request);

        // Otherwise someone who can't approve campaigns could switch approval off and send unreviewed.
        if ($approvalChanged && ! $user->hasWorkspacePermission($workspace, Permission::CampaignsApprove)) {
            throw ValidationException::withMessages(['requires_campaign_approval' => __('Only people who can approve campaigns can change this.')]);
        }

        $workspace->update($validated);

        if ($approvalChanged) {
            Log::notice('Campaign approval setting changed', ['workspace' => $workspace->id, 'user' => $user->getAuthIdentifier(), 'to' => $approval]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            ! $approvalChanged => __('Sending settings saved.'),
            $approval => __('Sending settings saved. Campaigns now need approval before they are sent.'),
            default => __('Sending settings saved. Campaigns no longer need approval.'),
        }]);

        return to_route('marketing.sending.edit');
    }

    /**
     * Resume sending after an automatic pause, once the cause has been dealt with.
     */
    public function resume(QueueCampaignBatches $queueBatches): RedirectResponse
    {
        $this->currentWorkspace->getOrFail()->update(['sending_paused_at' => null, 'sending_paused_reason' => null]);

        Campaign::query()->broadcasts()->where('status', CampaignStatus::Sending)
            ->each(fn (Campaign $campaign) => $queueBatches->handle($campaign));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sending resumed.')]);

        return to_route('marketing.sending.edit');
    }
}
