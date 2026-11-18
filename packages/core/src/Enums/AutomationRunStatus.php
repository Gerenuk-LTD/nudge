<?php

namespace Gerenuk\Marketing\Enums;

enum AutomationRunStatus: string
{
    /** Due to run its next step now. */
    case Active = 'active';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Exited = 'exited';
}
