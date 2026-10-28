<?php

namespace Gerenuk\Marketing\Enums;

enum ContactSource: string
{
    case Manual = 'manual';
    case Api = 'api';
    case Import = 'import';
}
