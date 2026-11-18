<?php

namespace Gerenuk\Marketing\Actions\Imports;

use Gerenuk\Marketing\Enums\ImportStatus;
use Gerenuk\Marketing\Jobs\ProcessImport;
use Gerenuk\Marketing\Models\Import;

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
