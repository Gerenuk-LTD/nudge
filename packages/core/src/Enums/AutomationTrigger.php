<?php

namespace Gerenuk\Nudge\Enums;

enum AutomationTrigger: string
{
    case ListJoined = 'list_joined';
    case TagAdded = 'tag_added';
    case SegmentEntered = 'segment_entered';
    case DateField = 'date_field';
    case Inactivity = 'inactivity';
    case ApiEvent = 'api_event';

    public function label(): string
    {
        return match ($this) {
            self::ListJoined => 'Joins a list',
            self::TagAdded => 'Is given a tag',
            self::SegmentEntered => 'Enters a segment',
            self::DateField => 'A date in their details comes round',
            self::Inactivity => 'Stops engaging',
            self::ApiEvent => 'Your app sends an event',
        };
    }
}
