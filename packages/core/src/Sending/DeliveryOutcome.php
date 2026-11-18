<?php

namespace Gerenuk\Nudge\Sending;

enum DeliveryOutcome
{
    case Sent;
    case Failed;
    /** Not sent: unsubscribed, archived or suppressed, or already claimed by another worker. */
    case Skipped;
    /** Not attempted because the provider's daily limit is used up. Try again tomorrow. */
    case OverDailyLimit;
}
