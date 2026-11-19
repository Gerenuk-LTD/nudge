<?php

namespace Gerenuk\Nudge\Actions\Imports;

use Gerenuk\Nudge\Enums\ImportStatus;
use Gerenuk\Nudge\Jobs\ProcessImport;
use Gerenuk\Nudge\Models\Import;

class StartImport
{
    /**
     * @param  array<int, string|null>  $mapping  Validated: one target per header, email mapped exactly once.
     * @param  list<string>  $tags
     */
    public function handle(Import $import, array $mapping, ?int $contactListId, array $tags): void
    {
        $import->update([
            'column_mapping' => $mapping,
            'contact_list_id' => $contactListId,
            'tags' => $tags,
            'status' => ImportStatus::Queued,
        ]);

        ProcessImport::dispatch($import);
    }
}
