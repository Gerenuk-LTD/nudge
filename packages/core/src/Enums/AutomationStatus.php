<?php

namespace Gerenuk\Nudge\Enums;

enum AutomationStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    /** No new contacts enter, and contacts already in it wait where they are. */
    case Paused = 'paused';
}
