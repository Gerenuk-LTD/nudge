<?php

namespace Gerenuk\Marketing\Enums;

enum SuppressionReason: string
{
    case Bounce = 'bounce';
    case Complaint = 'complaint';
    case Manual = 'manual';
}
