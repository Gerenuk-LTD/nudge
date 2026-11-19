<?php

namespace Gerenuk\Nudge\Events;

use Gerenuk\Nudge\Contracts\NudgeUser;
use Gerenuk\Nudge\Models\Workspace;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Lets host applications react to a new workspace, for example to start a trial.
 */
class WorkspaceCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly NudgeUser $owner,
    ) {}
}
