<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Campaigns\QueueCampaignBatches;
use Gerenuk\Marketing\Enums\CampaignStatus;
use Gerenuk\Marketing\Models\Campaign;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SendingSettingsController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function edit(): Response
    {
        $workspace = $this->currentWorkspace->getOrFail();

        return Inertia::render('marketing/workspace/sending', [
            'postal_address' => $workspace->postal_address,
            'requires_campaign_approval' => $workspace->requires_campaign_approval,
            'paused_at' => $workspace->sending_paused_at?->toIso8601String(),
            'paused_reason' => $workspace->sending_paused_reason,
            'thresholds' => config('marketing.sending.auto_pause'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->currentWorkspace->getOrFail()->update($request->validate([
            'postal_address' => ['required', 'string', 'max:500'],
            'requires_campaign_approval' => ['required', 'boolean'],
        ]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sending settings saved.')]);

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
