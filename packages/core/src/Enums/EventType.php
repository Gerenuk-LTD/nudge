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
    /** A named event a customer's app sent through the API. */
    case Custom = 'custom';
    case AutomationEntered = 'automation_entered';
    case AutomationCompleted = 'automation_completed';
    case AutomationExited = 'automation_exited';
}
