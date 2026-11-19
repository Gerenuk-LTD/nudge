<?php

namespace Gerenuk\Nudge\Sending;

use Gerenuk\Nudge\Enums\EventType;
use Gerenuk\Nudge\Models\ActivityEvent;
use Gerenuk\Nudge\Models\Workspace;

/**
 * Pauses a workspace's sending when its bounce or complaint rate over the last 7 days is too high,
 * which protects the customer's own provider account from suspension.
 */
class AutoPause
{
    public function check(Workspace $workspace): bool
    {
        if ($workspace->isSendingPaused()) {
            return false;
        }

        $counts = ActivityEvent::query()
            ->where('occurred_at', '>=', now()->subDays(7))
            ->whereIn('type', [EventType::Sent, EventType::Bounced, EventType::Complained])
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $sent = (int) ($counts[EventType::Sent->value] ?? 0);

        if ($sent < (int) config('nudge.sending.auto_pause.minimum_sends')) {
            return false;
        }

        $bounceRate = (int) ($counts[EventType::Bounced->value] ?? 0) / $sent;
        $complaintRate = (int) ($counts[EventType::Complained->value] ?? 0) / $sent;

        $reason = match (true) {
            $bounceRate > (float) config('nudge.sending.auto_pause.bounce_rate') => sprintf('Bounce rate reached %.1f%% over the last 7 days.', $bounceRate * 100),
            $complaintRate > (float) config('nudge.sending.auto_pause.complaint_rate') => sprintf('Complaint rate reached %.2f%% over the last 7 days.', $complaintRate * 100),
            default => null,
        };

        if ($reason === null) {
            return false;
        }

        $workspace->update(['sending_paused_at' => now(), 'sending_paused_reason' => $reason]);

        return true;
    }
}
