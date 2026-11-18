<?php

namespace Gerenuk\Marketing\Jobs;

use Gerenuk\Marketing\Enums\ImportStatus;
use Gerenuk\Marketing\Models\Import;

/**
 * Runs daily and removes imports that were uploaded but never started, in every workspace, along with their
 * spreadsheets. An abandoned upload is a full copy of someone's contact list, so it isn't kept for long.
 */
class PruneStaleImports
{
    public const AFTER_DAYS = 7;

    public function __invoke(): void
    {
        Import::withoutGlobalScopes()
            ->where('status', ImportStatus::Pending)
            ->where('created_at', '<', now()->subDays(self::AFTER_DAYS))
            ->each(function (Import $import): void {
                ProcessImport::deleteUpload($import);
                $import->delete();
            });
    }
}
