<?php

namespace Gerenuk\Nudge\Enums;

enum SuppressionReason: string
{
    case Bounce = 'bounce';
    case Complaint = 'complaint';
    case Manual = 'manual';
}
