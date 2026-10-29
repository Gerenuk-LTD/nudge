<?php

namespace Gerenuk\Marketing\Enums;

enum RecipientStatus: string
{
    case Queued = 'queued';
    /** Waiting for an A/B test to pick a winner. */
    case Held = 'held';
    /** Claimed by a worker. A recipient left here after a crash is marked failed by the stalled-send sweep, never retried, so nobody gets a duplicate. */
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    /** Removed at send time: unsubscribed, archived or suppressed since the audience was built. */
    case Skipped = 'skipped';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Complained = 'complained';
}
