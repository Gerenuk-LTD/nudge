<?php

namespace Gerenuk\Nudge\Http\Controllers\Public;

use Gerenuk\Nudge\Actions\Campaigns\RecordEngagement;
use Gerenuk\Nudge\Http\Controllers\Controller;
use Gerenuk\Nudge\Models\CampaignLink;
use Gerenuk\Nudge\Models\CampaignRecipient;
use Gerenuk\Nudge\Models\Workspace;
use Gerenuk\Nudge\Sending\TrackingUrls;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Click redirects and the open pixel. Both work without a session and never show errors to recipients.
 */
class TrackingController extends Controller
{
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(
        private readonly TrackingUrls $urls,
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly RecordEngagement $engagement,
    ) {}

    public function click(Request $request, int $recipient, int $link, string $signature): RedirectResponse
    {
        abort_unless($this->urls->verify("click:{$recipient}:{$link}", $signature), 404);

        $recipientModel = CampaignRecipient::withoutGlobalScopes()->find($recipient);
        $linkModel = CampaignLink::withoutGlobalScopes()->find($link);

        abort_if($recipientModel === null || $linkModel === null || $linkModel->campaign_id !== $recipientModel->campaign_id, 404);

        $this->currentWorkspace->runAs(
            Workspace::findOrFail($recipientModel->workspace_id),
            fn () => $this->engagement->click($recipientModel, $linkModel, $request->userAgent()),
        );

        return redirect()->away($linkModel->url);
    }

    public function open(Request $request, int $recipient, string $signature): Response
    {
        if ($this->urls->verify("open:{$recipient}", $signature) && ($model = CampaignRecipient::withoutGlobalScopes()->find($recipient)) !== null) {
            $this->currentWorkspace->runAs(
                Workspace::findOrFail($model->workspace_id),
                fn () => $this->engagement->open($model, $request->userAgent()),
            );
        }

        return response((string) base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
