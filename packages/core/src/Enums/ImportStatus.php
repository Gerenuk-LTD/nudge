<?php

namespace Gerenuk\Marketing\Enums;

enum ImportStatus: string
{
    /** Uploaded and waiting for the user to map columns. */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
