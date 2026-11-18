<?php

namespace Gerenuk\Nudge\Http\Controllers\Public;

use Gerenuk\Nudge\Actions\Campaigns\RecordEngagement;
use Gerenuk\Nudge\Enums\ContactStatus;
use Gerenuk\Nudge\Enums\EventType;
use Gerenuk\Nudge\Http\Controllers\Controller;
use Gerenuk\Nudge\Models\ActivityEvent;
use Gerenuk\Nudge\Models\CampaignRecipient;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Sending\TrackingUrls;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The unsubscribe page, plus the one-click POST mailbox providers send (RFC 8058). The page also lets recipients pause
 * emails for a while instead; a pause isn't a consent change, so one-click and "Unsubscribe" always unsubscribe.
 */
class UnsubscribeController extends Controller
{
    public function __construct(
        private readonly TrackingUrls $urls,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    public function show(int $recipient, string $signature): Response
    {
        $model = $this->recipient($recipient, $signature);
        $workspace = Workspace::findOrFail($model->workspace_id);

        return $this->currentWorkspace->runAs($workspace, fn () => Inertia::render('nudge/public/unsubscribe', [
            'workspace' => $workspace->name,
            'email' => $model->email,
            'unsubscribed' => $model->contact->status === ContactStatus::Unsubscribed,
            'pausedUntil' => $model->contact->isPaused() ? $model->contact->paused_until?->toIso8601String() : null,
            'action' => $this->urls->unsubscribe($model),
            'pauseAction' => $this->urls->pause($model),
        ]));
    }

    /**
     * Pause emails to a subscribed contact for 30 or 90 days. Pausing never subscribes anyone again.
     */
    public function pause(Request $request, int $recipient, string $signature): RedirectResponse
    {
        $model = $this->recipient($recipient, $signature);
        $days = (int) $request->validate(['days' => ['required', 'integer', 'in:30,90']])['days'];

        $this->currentWorkspace->runAs(Workspace::findOrFail($model->workspace_id), function () use ($model, $days): void {
            DB::transaction(function () use ($model, $days): void {
                $contact = $model->contact;

                if ($contact->status !== ContactStatus::Subscribed) {
                    return;
                }

                $contact->update(['paused_until' => now()->addDays($days)]);
                ActivityEvent::recordFor($model, EventType::Paused, ['days' => $days]);
            });
        });

        return redirect()->to($this->urls->unsubscribe($model));
    }

    public function store(Request $request, int $recipient, string $signature, RecordEngagement $engagement): RedirectResponse|HttpResponse
    {
        $model = $this->recipient($recipient, $signature);

        $this->currentWorkspace->runAs(Workspace::findOrFail($model->workspace_id), fn () => $engagement->unsubscribe($model));

        // Mailbox providers' one-click requests expect a plain success, not a page.
        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return response()->noContent(200);
        }

        return redirect()->to($this->urls->unsubscribe($model));
    }

    private function recipient(int $recipient, string $signature): CampaignRecipient
    {
        abort_unless($this->urls->verify("unsubscribe:{$recipient}", $signature), 404);

        return CampaignRecipient::withoutGlobalScopes()->with(['contact' => fn ($query) => $query->withoutGlobalScopes()])->findOrFail($recipient);
    }
}
