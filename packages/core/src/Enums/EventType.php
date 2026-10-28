<?php

namespace Gerenuk\Marketing\Enums;

enum EventType: string
{
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Deferred = 'deferred';
    case Bounced = 'bounced';
    case Dropped = 'dropped';
    case Complained = 'complained';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Unsubscribed = 'unsubscribed';
}
