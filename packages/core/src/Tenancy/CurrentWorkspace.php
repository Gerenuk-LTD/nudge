<?php

namespace Gerenuk\Nudge\Tenancy;

use Gerenuk\Nudge\Models\Workspace;

/**
 * Holds the workspace that tenant-owned queries are scoped to for the
 * current request or job. Bound as a scoped singleton.
 */
class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->workspace?->id;
    }

    public function getOrFail(): Workspace
    {
        return $this->workspace ?? throw new MissingWorkspaceException;
    }

    /**
     * Run the callback with the given workspace as current, restoring the previous one afterwards.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runAs(Workspace $workspace, callable $callback): mixed
    {
        $previous = $this->workspace;
        $this->workspace = $workspace;

        try {
            return $callback();
        } finally {
            $this->workspace = $previous;
        }
    }
}
