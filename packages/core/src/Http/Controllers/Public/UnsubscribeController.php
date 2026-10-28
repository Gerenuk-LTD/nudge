<?php

namespace Gerenuk\Marketing\Http\Controllers\Public;

use Gerenuk\Marketing\Actions\Campaigns\RecordEngagement;
use Gerenuk\Marketing\Enums\ContactStatus;
use Gerenuk\Marketing\Http\Controllers\Controller;
use Gerenuk\Marketing\Models\CampaignRecipient;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Sending\TrackingUrls;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The unsubscribe page, plus the one-click POST mailbox providers send (RFC 8058).
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

        return $this->currentWorkspace->runAs($workspace, fn () => Inertia::render('marketing/public/unsubscribe', [
            'workspace' => $workspace->name,
            'email' => $model->email,
            'unsubscribed' => $model->contact->status === ContactStatus::Unsubscribed,
            'action' => $this->urls->unsubscribe($model),
        ]));
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
