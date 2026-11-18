<?php

namespace Gerenuk\Nudge\Enums;

enum CampaignKind: string
{
    /** A one-off send to an audience. */
    case Broadcast = 'broadcast';

    /** The email of an automation step, sent to one contact at a time. */
    case Automation = 'automation';
}
