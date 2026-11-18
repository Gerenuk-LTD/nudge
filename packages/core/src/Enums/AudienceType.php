<?php

namespace Gerenuk\Marketing\Enums;

enum AudienceType: string
{
    case AllContacts = 'all';
    case Lists = 'lists';
    case Segment = 'segment';
}
