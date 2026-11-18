<?php

namespace Gerenuk\Marketing\Tenancy;

use LogicException;

class MissingWorkspaceException extends LogicException
{
    public function __construct()
    {
        parent::__construct('A tenant-owned model was queried without a current workspace.');
    }
}
