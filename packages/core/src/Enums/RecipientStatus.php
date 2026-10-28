<?php

namespace Gerenuk\Marketing\Enums;

enum RecipientStatus: string
{
    case Queued = 'queued';
    /** Claimed by a worker. A recipient left here after a crash is not retried, so nobody gets a duplicate. */
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    /** Removed at send time: unsubscribed, archived or suppressed since the audience was built. */
    case Skipped = 'skipped';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Complained = 'complained';
}
